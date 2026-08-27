# Security

The page-metrics tiles are visible to every backend user by default. To hide
them for a user group (or an individual user), set in that group's/user's
**User TSconfig**:

```
tx_pagemetrics.disabled = 1
```

This is enforced both where the tiles are injected into the page module and
on the AJAX route that loads them, so a user without access cannot reach the
data by calling that route directly either.
