# Backend module

**Web > Page metrics** is the extension's own place: a page picked in the page
tree, a period, and a view of that page's numbers. It answers the questions the
tile popover in **Web > Page** cannot hold, and it works without EXT:dashboard.

The module is optional in the sense that matters: nothing else needs it. The
tiles in the page module and the dashboard widgets keep working exactly as they
did, and a site that only wants those can ignore it.

## What it shows

**Page.** What the tiles in **Web > Page** show, in a form you can sit and read:
the chart across the full width, every metric as a labelled row with its figure,
the previous period's figure and the change, and the period's markers as a list
with their kind and text.

The difference from the tile popover is deliberate. Each metric carries the
provider's own description as text beside it rather than behind a hover. The
reason to have a module at all is room for explanation, and putting it back
behind a mouse would be the same problem in a new place.

Three states in the change column, the same three the tiles draw and for the
same reasons: a percentage where there is something to compare against, `new`
where the page had no figures in the previous period, and a dash where that
period was never imported, because a percentage against days nobody fetched is
not a smaller number, it is not a number.

Further views follow one at a time and each is its own change.

## Period

The same three periods the tiles offer, 7, 30 and 90 days. The choice is stored
per user in TYPO3's `moduleData`, the mechanism the info module uses for its own
`depth` and `lang`, so it survives a page switch and a logout. It is deliberately
separate from the page module's own period: the two surfaces answer different
questions and a reader changing one should not find the other moved.

## Downloading a view

Every view has a **Download CSV** button in the docheader that writes exactly
what the view shows: the same rows, the same order, the same period. The pages
view exports every row, not the page of them on screen.

Two properties worth knowing before you open the file. It is semicolon
separated with a UTF-8 byte order mark, because Excel splits on the list
separator of the machine it runs on and a comma file opens as a single column
on a German-locale installation. And the header names metric identifiers
(`matomo.pageviews`) rather than labels, so two exports of one view taken in
different backend languages are comparable; the readable name is in the module
beside it.

Values are raw and in their own unit. A time on page is `183` and not `3:03`,
currency is in minor units, and an empty cell means the figure does not exist
rather than being zero.

## Access

The module is `access: user`, so it is not restricted to administrators. The
numbers are for editors, and a module only administrators can see does not
answer the question it exists for.

Every request checks the selected page for `PAGE_SHOW` before reading anything,
because the page uid arrives as a request parameter. See
[Security](security.md) for the switch that turns the module off for a user, and
for how to take its menu entry away as well.
