# Pinned Symfony AI search components

Source: [Johannes Wachter’s tool-search proposal #87](https://github.com/wachterjohannes/symfony-ai/pull/87), commit `c9b2fc128e50524e6ca7585d2a7771925962ffad` (open, checked 14 November 2026).

These three MIT-licensed classes come from `src/agent/src/Toolbox/ToolSearch/` at that revision. Only the namespace is changed, to avoid shadowing installed Symfony classes. Copyright headers and the original license are retained. This is a pinned compatibility snapshot, not a Symfony release or a separate search implementation.

The adapter prefers the native Symfony `Bm25ToolSearch` when it implements the proposed `ToolSearchInterface`. Until that API is available, the existing Symfony integration uses this snapshot. Remove it after adopting a supported release and rerunning the discovery and access-control checks. No WIP agent loop, provider bridge or runtime executor is copied.

| Original file | Original SHA-256, before namespace replacement |
| --- | --- |
| `ToolSearchInterface.php` | `ec60e01d659fa962bf2b849b2cfe82432df300afcd22d0ba6ee8539c629d70a0` |
| `KeywordToolSearch.php` | `403a4979f520b8996b6e99a5f76c731d42d12b8901066a3ad6a4f462065d6b3a` |
| `Bm25ToolSearch.php` | `afeb6bf2cf71275da706d4fc0febf4632cfb5dbd9de4a935e85363fb19471fa6` |
