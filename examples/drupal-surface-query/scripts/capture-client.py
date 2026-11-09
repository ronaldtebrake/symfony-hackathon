import os, sys, json, re, subprocess, time, pathlib, selectors, tempfile
example=pathlib.Path(__file__).resolve().parents[1]
root=example.parents[1]
os.umask(0o077)
check_only='--check' in sys.argv[1:]
capture=example/'var'/(('client-check-' if check_only else 'client-')+time.strftime('%Y%m%d-%H%M%S')+'-'+os.urandom(3).hex())
capture.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
capture.mkdir(mode=0o700)
cwd=tempfile.mkdtemp(prefix='surface-agent-empty-')
scenario=json.loads(subprocess.check_output(['php', '-r', 'require $argv[1]; echo json_encode(["prompt"=>SurfaceProof\\Conversation::PROMPT,"questions"=>SurfaceProof\\Conversation::QUESTIONS], JSON_THROW_ON_ERROR);', str(example/'src/Conversation.php')], text=True))
cmd=['codex','app-server','--listen','stdio://','-c','mcp_servers={}']
for feature in ['apps','plugins','shell_tool','unified_exec','multi_agent','browser_use','view_image']:
 cmd+=['--disable',feature]
cmd+=['--enable','skip_host_skill_discovery','-c','web_search="disabled"']
config_path=pathlib.Path.home()/'.codex/config.toml'
config=config_path.read_text() if config_path.exists() else ''
for name in re.findall(r'^\[mcp_servers\.("(?:[^"\\]|\\.)+"|[a-zA-Z0-9_-]+)\]$',config,re.M):
 cmd+=['-c','mcp_servers.'+name+'.enabled=false']
server={'command':subprocess.check_output(['which','php'],text=True).strip(),'args':[str(example/'mcp.php'),str(capture)],'cwd':str(root),'enabled':True,'required':True,'startup_timeout_sec':20,'tool_timeout_sec':30,'default_tools_approval_mode':'approve','enabled_tools':['tools_search','tools_describe','php_execute']}
# Per-process overrides only. Do not write Codex configuration.
# Both turns share one ephemeral client and one MCP server process.
for key,value in server.items():
 cmd+=['-c','mcp_servers.data_surface.'+key+'='+json.dumps(value)]
p=subprocess.Popen(cmd,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=False,cwd=cwd)
sel=selectors.DefaultSelector();sel.register(p.stdout,selectors.EVENT_READ)
buffer=b'';seq=0;pending={};events=[];report={'version':1,'kind':'data-surface-client-conversation','client':'Codex app-server','createdAt':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'status':'running','questions':scenario['questions'],'systemPrompt':scenario['prompt'],'questionOrigin':'Operator-authored two-turn scenario; model-generated PHP and answers.','disabledFeatures':['apps','plugins','shell_tool','unified_exec','multi_agent','browser_use','view_image'],'turns':[]}
def save(): (capture/'client.json').write_text(json.dumps(report,indent=2))
def send(obj): p.stdin.write(json.dumps(obj).encode()+b'\n');p.stdin.flush()
def next_message(deadline):
 global buffer
 while time.monotonic()<deadline:
  if b'\n' in buffer:
   line,buffer=buffer.split(b'\n',1)
   if line:return json.loads(line)
  if p.poll() is not None:raise RuntimeError('App-server exited')
  if sel.select(min(1,max(0,deadline-time.monotonic()))):
   data=os.read(p.stdout.fileno(),65536)
   if not data:raise RuntimeError('App-server closed stdout')
   buffer+=data
   if len(buffer)>2097152:raise RuntimeError('Notification too large')
 raise TimeoutError('App-server deadline exceeded')
def observe(msg):
 if 'method' not in msg:return
 method=msg['method'];params=msg.get('params',{})
 if 'id' in msg:
  # Never auto-grant an unexpected permission or tool request.
  raise RuntimeError('Unexpected client request: '+method)
 if method=='item/completed':
  item=params.get('item',{})
  kind=item.get('type','unknown')
  report.setdefault('itemTypes',{})[kind]=report.setdefault('itemTypes',{}).get(kind,0)+1
  if kind not in ['agentMessage','mcpToolCall','userMessage','reasoning']:
   raise RuntimeError('Unexpected agent action: '+kind)
  if item.get('type') in ['agentMessage','mcpToolCall','userMessage']:
   events.append({'method':method,'threadId':params.get('threadId'),'turnId':params.get('turnId'),'item':item})
   (capture/'client-events.json').write_text(json.dumps(events,indent=2))
   print('Completed '+item.get('type','unknown')+' '+item.get('tool',''),flush=True)
 elif method=='thread/tokenUsage/updated':
  report['tokenUsage']=params.get('tokenUsage');save()
 elif method=='error':
  report['clientError']=True;save()
def rpc(method,params,timeout=45):
 global seq
 seq+=1;request=seq;send({'id':request,'method':method,'params':params});deadline=time.monotonic()+timeout
 while True:
  msg=next_message(deadline)
  if msg.get('id')==request:
   if 'error' in msg:raise RuntimeError('RPC '+method+' failed (code '+str(msg['error'].get('code'))+')')
   return msg['result']
  observe(msg)
try:
 print('Capture '+str(capture),flush=True);save()
 rpc('initialize',{'clientInfo':{'name':'surface_conversation_capture','version':'1.0'},'capabilities':{'experimentalApi':True}})
 send({'method':'initialized'})
 start=rpc('thread/start',{'cwd':cwd,'ephemeral':True,'sandbox':'read-only','approvalPolicy':'never','baseInstructions':scenario['prompt']+' Use only the data_surface MCP server. Do not edit files or delegate work.','config':{'features.multi_agent':False},'experimentalRawEvents':False},timeout=60)
 report.update({'threadId':start['thread']['id'],'model':start['model'],'modelProvider':start['modelProvider'],'sandbox':start['sandbox'],'ephemeral':start['thread'].get('ephemeral')});save()
 report['hostDiscoveryProbe']=rpc('mcpServer/tool/call',{'threadId':report['threadId'],'server':'data_surface','tool':'tools_search','arguments':{'query':'drupal'}},timeout=45);save()
 effective=rpc('config/read',{'includeLayers':False,'cwd':cwd})['config']
 report['enabledMcpServers']=sorted(name for name,server in effective.get('mcp_servers',{}).items() if server.get('enabled',True));save()
 del effective
 if report['enabledMcpServers']!=['data_surface']:
  raise RuntimeError('Unexpected enabled MCP server configuration; refusing inference')
 probe=report['hostDiscoveryProbe']
 if probe.get('isError') or [tool['name'] for tool in probe.get('structuredContent',{}).get('tools',[])]!=['drupal.search']:
  raise RuntimeError('Unexpected query catalog; refusing inference')
 for index,question in enumerate([] if check_only else scenario['questions']):
  turn=rpc('turn/start',{'threadId':report['threadId'],'input':[{'type':'text','text':question}],'effort':'medium'})['turn']
  record={'question':question,'turnId':turn['id']};report['turns'].append(record);save()
  deadline=time.monotonic()+240
  while True:
   msg=next_message(deadline);observe(msg)
   if msg.get('method')=='turn/completed' and msg['params']['turn']['id']==turn['id']:
    record['status']=msg['params']['turn']['status']
    record['messages']=[e['item'] for e in events if e['turnId']==turn['id'] and e['item']['type']=='agentMessage']
    save();break
  if record['status']!='completed':raise RuntimeError('Agent turn did not complete')
  print('Turn '+str(index+1)+' completed',flush=True)
 report['status']='ready' if check_only else 'captured';save()
except Exception as error:
 report['status']='failed';report['error']=str(error);save();print(str(error),flush=True)
finally:
 p.terminate()
 try:p.wait(timeout=10)
 except subprocess.TimeoutExpired:p.kill();p.wait()
 sel.close()
 try:os.rmdir(cwd)
 except OSError:pass
print(str(capture),flush=True)
sys.exit(0 if report['status'] in ['ready','captured'] else 1)
