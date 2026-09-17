# Security

The page-metrics tiles are visible to every backend user by default. To hide
them for a user group (or an individual user), set in that group's/user's
**User TSconfig**:

```
tx_typo3pagemetrics.disabled = 1
```

This is enforced both where the tiles are injected into the page module and
on every AJAX route behind them, so a user without access cannot reach the
data by calling one of those routes directly either.

## Who may create a marker

Manual annotation records ("Page metrics annotation") follow TYPO3's own
permission system. They are created on the page a site names as
`pageMetrics.annotations.storagePid`, so a backend group needs that table
under **Allowed tables** and content-edit permission on that page before any
of its members can add a marker. The **Add marker** entry in the page module
appears only for a user who has both, and the storage page it points at comes
from the site configuration rather than from the request, so the link cannot
be aimed at a page its user was never granted. DataHandler enforces the same
permissions when the form is saved, so a hand-built URL gains nothing either.
