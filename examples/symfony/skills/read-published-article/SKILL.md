---
name: read-published-article
description: Look up the current title of a published article by its numeric ID in a Symfony application.
---

# Read a published article

Use the Symfony catalog connection for the person asking. Search for `publication_read` with `search_tools`. If the catalog returns its contract, pass the requested ID as an integer. For article 101 the arguments to `execute_tools` are:

```json
{"calls":[{"name":"publication_read","arguments":{"id":101}}]}
```

Answer with the title in this call’s result. If the contract is hidden, report that the lookup is unavailable. If access is denied, stop and report the denial. An earlier title in the conversation cannot establish what the caller may read now.
