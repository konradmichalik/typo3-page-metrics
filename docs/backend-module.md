# Backend module

**Web > Page metrics** is the extension's own place: a page picked in the page
tree, a period, and a view of that page's numbers. It answers the questions the
tile popover in **Web > Page** cannot hold, and it works without EXT:dashboard.

The module is optional in the sense that matters: nothing else needs it. The
tiles in the page module and the dashboard widgets keep working exactly as they
did, and a site that only wants those can ignore it.

## What it shows

**Page.** What the tiles in **Web > Page** show, in a form you can sit and read:
the chart across the full width, every metric as its own block, and the period's
markers as a list with their kind and text.

A metric block carries what the tile keeps behind a hover. The provider's own
description, the figure with its change badge, the period it is measured
against, and the qualifying figures the popover shows as chips: the last day of
the period and the one before it, the peak, the average across the days that
have data, the share that arrived through a redirect, the part counted on
records below the page, and what the typical page of this site did. Each one
with the sentence that says what it is, which is the part a tooltip can only
ever give one at a time. The crawler summary also lists what it is made of.

The difference from the tile popover is deliberate. The reason to have a module
at all is room for explanation, and putting it back behind a mouse would be the
same problem in a new place. It is blocks rather than a wide table for the same
reason: seven figures as seven columns is a horizontal scroll on every backend
narrower than a desktop, and a column header cannot carry a sentence.

Three states for the change, the same three the tiles draw and for the same
reasons: a percentage where there is something to compare against, `new` where
the page had no figures in the previous period, and no badge at all where that
period was never imported, because a percentage against days nobody fetched is
not a smaller number, it is not a number.

A change badge is coloured by whether the metric got better, not by which way it
moved, so a metric where neither direction is an improvement stays grey with its
arrow pointing up. The module says so in words under the figure; the tile says
it in the badge's tooltip.

**Compare.** Two periods of the same page held against each other, metric by
metric, with a chart of the site's primary metric over both.

Which two periods is a choice of four, all keeping the length of the period you
selected and only moving the start back: the period immediately before, or the
same days one month, one quarter or one year earlier. Only the start moves,
because two totals taken over windows of different lengths are not comparable
and nothing downstream could tell them apart.

The chart draws both periods to **one** scale, which is what lets you compare
their heights rather than only their shapes. Its x axis counts days into each
period rather than dates, because the two cover different ones: day one is held
against day one. The earlier period is the dashed line, dashed rather than
differently coloured because it is the same metric and because a dash survives a
forced-colours mode where a hue does not.

A difference is called significant only when it clears both thresholds the
metric itself names, which is the same rule the dashboard's trend widget ranks
by. Anything under that is printed plainly and named as normal variation.

**Pages.** Every page of the site as a table, sortable by any metric and by
content decay, with pages you cannot see in the page tree left out.

The column the table is sorted by carries three things in one cell: the total
for the period, that same figure drawn day by day, and the change against the
period before. All three follow the sort, so ordering by another metric draws
that one instead. A day that was never imported is a gap in the drawing rather
than a zero, and the drawing says nothing the figure and the change beside it
do not, which is why a screen reader is given those two and not the picture.

What each column counts is stated once above the table rather than once in
every header. That repetition was most of why the table had to be scrolled
sideways, and putting it behind a hover would have been the mistake this module
exists to avoid.

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
