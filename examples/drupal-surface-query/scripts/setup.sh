#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
site=${SURFACE_PROOF_SITE:?Set SURFACE_PROOF_SITE to the existing Rosetta DDEV site directory}
# Copy only this optional fixture module and its operator script. No upstream checkout is changed.
python3 - "$root" <<'PY' | (cd "$site" && ddev exec python3 -)
import base64,json,sys
from pathlib import Path
root=Path(sys.argv[1]); files={}
for p in (root/'drupal/code_mode_surface_proof').iterdir():
    files['web/modules/custom/code_mode_surface_proof/'+p.name]=base64.b64encode(p.read_bytes()).decode()
files['web/modules/custom/code_mode_surface_proof/proof-control.php']=base64.b64encode((root/'scripts/drupal.php').read_bytes()).decode()
print('from pathlib import Path\nimport base64\nfiles='+repr(files)+'\nfor name,data in files.items():\n p=Path(name);p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(base64.b64decode(data))')
PY
(cd "$site" && ddev drush en code_mode_surface_proof -y)
(cd "$site" && ddev exec env SURFACE_PROOF_ACTION=setup vendor/bin/drush --quiet php:script /var/www/html/web/modules/custom/code_mode_surface_proof/proof-control.php)
(cd "$site" && ddev drush cr)
