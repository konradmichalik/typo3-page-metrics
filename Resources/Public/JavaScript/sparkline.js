const SVG_NS = 'http://www.w3.org/2000/svg';
const SPARKLINE_HEIGHT = 52;

// Breathing room above and below the plotted line, see plotAgainstBounds().
// Deeper at the bottom: the area is filled down to the chart's own edge, so a
// lowest value sitting on the floor would read as zero rather than as the
// smallest number in the period.
const PLOT_INSET_TOP = 3;
const PLOT_INSET_BOTTOM = 9;

// The period strip's thickness, in the same user units as the plot, so it
// keeps its proportion whether it is drawn in a 52px popover sparkline or a
// 260px dashboard chart. The curve's lowest point sits PLOT_INSET_BOTTOM
// above the floor, so the strip never runs underneath it.
const ANNOTATION_BAND_HEIGHT = 3;
// Fixed total width regardless of how many days are plotted (7/30/90, per
// the period selector): points get closer together for a longer period
// rather than the popover growing wider. 29 * 4px matches the previous
// fixed 30-day sparkline's width, so the 30-day case looks unchanged.
const SPARKLINE_WIDTH = 29 * 4;
const openTiles = new Set();

// Every user-facing string this file renders, aria-labels included, comes
// from the XLF, handed over as one
// data-labels attribute on the container the server already renders. A
// second request just to fetch a handful of labels would be worse, and
// hard-coding them here is what this replaced: they stayed English whatever
// the backend language was, and the external link claimed "Matomo" even when
// the tile came from GA4 or an access log.
//
// The fallbacks are not a translation, they are what keeps a sparkline
// readable if the attribute is ever missing or malformed.
const FALLBACK_LABELS = {
  lastDay: 'Last day',
  previousDay: 'Previous day',
  peak: 'Peak',
  average: 'Average',
  viaRedirects: 'Via redirects',
  typicalPage: 'Typical page',
  typicalPageHint: 'The median of the %1$s pages on this site with data in this period',
  typicalPageTopFifth: 'This page is in the top fifth',
  typicalPageAboveMedian: 'This page is above it',
  typicalPageAtMedian: 'This page is the typical one',
  typicalPageBelowMedian: 'This page is below it',
  typicalPageBottomFifth: 'This page is in the bottom fifth',
  lastDayHint: 'The most recent day of the selected period',
  previousDayHint: 'The day before the most recent one',
  peakHint: 'The highest value a single day reached',
  averageHint: 'The mean across the days that have data',
  viaRedirectsHint: 'The share of this metric that arrived through a redirect',
  breakdown: 'Made up of',
  noData: 'No data',
  externalLink: 'Open in analytics tool',
  sliderPrevious: 'Previous',
  sliderNext: 'Next',
  sliderPage: 'Page %1$s',
  chartSummary: 'Chart from %1$s to %2$s, values from %3$s to %4$s',
  chartSummaryDates: 'Chart from %1$s to %2$s',
  'annotationCategory.release': 'Release',
  'annotationCategory.campaign': 'Campaign',
  'annotationCategory.content': 'Content change',
  'annotationCategory.redesign': 'Redesign',
  'annotationCategory.incident': 'Incident',
  'annotationCategory.seo': 'SEO change',
  'annotationCategory.external': 'External event',
};

function getLabels(element) {
  const container = element.closest('[data-labels]');
  if (!container) {
    return FALLBACK_LABELS;
  }

  try {
    return { ...FALLBACK_LABELS, ...JSON.parse(container.dataset.labels || '{}') };
  } catch {
    return FALLBACK_LABELS;
  }
}

const CHEVRON_LEFT_PATH = 'M15 6l-6 6l6 6';
const CHEVRON_RIGHT_PATH = 'M9 6l6 6l-6 6';

// Tabler Icons (MIT, see Resources/Public/Icons/TABLER_LICENSE.txt). Inline
// rather than registered through Configuration/Icons.php: the popover is
// built here in JavaScript, where TYPO3's icon API is not reachable, the
// same reason the chevrons above are inline.
const STAT_ICON_PATHS = {
  lastDay: ['M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12', 'M16 3l0 4', 'M8 3l0 4', 'M4 11l16 0', 'M8 15h2v2h-2l0 -2'],
  previousDay: ['M12.5 21h-6.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v8', 'M16 3v4', 'M8 3v4', 'M4 11h16', 'M16 19h6'],
  peak: ['M12 4l0 10', 'M12 4l4 4', 'M12 4l-4 4', 'M4 20l16 0'],
  average: ['M3 21l18 -18', 'M4 12a8 8 0 1 0 16 0a8 8 0 1 0 -16 0'],
  viaRedirects: ['M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0', 'M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4', 'M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5'],
  typicalPage: ['M7 20l10 0', 'M6 6l6 -1l6 1', 'M12 3l0 17', 'M9 12l-3 -6l-3 6a3 3 0 0 0 6 0', 'M21 12l-3 -6l-3 6a3 3 0 0 0 6 0'],
};

function statIconSvg(paths) {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${paths.map((path) => `<path d="${path}" />`).join('')}</svg>`;
}

function chevronSvg(path) {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="${path}" /></svg>`;
}

function getAnnotations(tileElement) {
  const container = tileElement.closest('.pagemetrics-tiles');
  if (!container || !container.dataset.annotations) {
    return [];
  }
  try {
    return JSON.parse(container.dataset.annotations);
  } catch {
    return [];
  }
}

// A day with no data at all arrives as null, not 0 (see
// DailyMetricsRepository::siteSeries()). The line has to break there rather
// than dive to the baseline, which is what made the first day of an import
// history, or any day the analytics source was unreachable, read as a
// traffic collapse.

function segmentsOf(points) {
  const segments = [];
  let current = [];

  points.forEach((point) => {
    if (point === null) {
      if (current.length > 0) {
        segments.push(current);
        current = [];
      }
      return;
    }
    current.push(point);
  });

  if (current.length > 0) {
    segments.push(current);
  }

  return segments;
}

function appendSeriesPath(svg, points, { stroke, withArea }) {
  segmentsOf(points).forEach((segment) => {
    // A single day surrounded by gaps has no line to draw, so it becomes a
    // dot: a zero-length stroke with a round cap, which non-scaling-stroke
    // keeps circular despite the SVG's preserveAspectRatio="none".
    if (segment.length === 1) {
      const [[x, y]] = segment;
      const dot = document.createElementNS(SVG_NS, 'line');
      dot.setAttribute('x1', x);
      dot.setAttribute('x2', x);
      dot.setAttribute('y1', y);
      dot.setAttribute('y2', y);
      dot.setAttribute('stroke', stroke);
      dot.setAttribute('stroke-width', '2.5');
      dot.setAttribute('stroke-linecap', 'round');
      dot.setAttribute('vector-effect', 'non-scaling-stroke');
      svg.appendChild(dot);
      return;
    }

    if (withArea) {
      const [firstX] = segment[0];
      const [lastX] = segment[segment.length - 1];
      const area = document.createElementNS(SVG_NS, 'polygon');
      // Closed to the bottom of the viewBox rather than to the inset the
      // line is drawn within, so the fill reaches the chart's own edge.
      const areaPoints = [...segment, [lastX, SPARKLINE_HEIGHT], [firstX, SPARKLINE_HEIGHT]];
      area.setAttribute('points', areaPoints.map((point) => point.join(',')).join(' '));
      area.setAttribute('fill', 'currentColor');
      area.setAttribute('fill-opacity', '0.2');
      area.setAttribute('stroke', 'none');
      svg.appendChild(area);
    }

    // Drawn over the area in the same colour at full strength, so it reads
    // as the darker edge of the shape rather than as a second element.
    const polyline = document.createElementNS(SVG_NS, 'polyline');
    polyline.setAttribute('points', segment.map((point) => point.join(',')).join(' '));
    polyline.setAttribute('fill', 'none');
    polyline.setAttribute('stroke', stroke);
    polyline.setAttribute('stroke-width', '1');
    polyline.setAttribute('vector-effect', 'non-scaling-stroke');
    svg.appendChild(polyline);
  });
}

function formatValueForUnit(value, unit) {
  if (unit === 'percent') {
    return `${value}%`;
  }
  if (unit === 'seconds') {
    return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
  }
  // Money is stored in minor units so the daily rows stay exact integers,
  // which is also why this divides rather than formats a decimal it was given.
  if (unit === 'currency') {
    return (value / 100).toFixed(2);
  }
  return String(value);
}

// The lowest and highest value actually plotted, or null when the series has
// nothing in it at all. Gaps are excluded rather than counted as zero: a day
// with no data would otherwise pull the reported minimum to 0 and describe a
// scale the line never touches.
function boundsOf(values) {
  const present = values.filter((value) => value !== null);

  return present.length > 0 ? [Math.min(...present), Math.max(...present)] : null;
}

// Where a day's value sits in the plot area, for both the popover sparkline
// and the dashboard chart, so the two cannot disagree about the same data.
//
// The bounds come from scaleBounds()'s percentile band rather than zero to
// the peak. A zero baseline sounds like the honest choice and is not one
// here: a page holding steady between 446 and 457 views puts its entire
// variation into the top 2% of the height and draws as a flat line, which
// tells the reader nothing the numbers above it have not already said. The
// chart's job on these surfaces is the shape.
//
// The axis is therefore truncated and carries no scale of its own: the chart
// is a silhouette of the period, and the numbers that give it magnitude are
// on the row directly above it.
function plotAgainstBounds(values, step) {
  const [scaleLow, scaleHigh] = scaleBounds(values.filter((value) => value !== null));
  const range = scaleHigh - scaleLow;
  // Inset so neither edge clips what is drawn on it. A 1px stroke sitting on
  // the viewBox boundary loses half its width to the SVG's own clip, and the
  // hover marker is a dot centred on the point, so a bottom-edge day lost its
  // lower half to the popover's overflow. Under the old zero-based scale only
  // a zero day ever reached that edge; under a percentile band every day at
  // or below the fifth percentile does.
  const plotHeight = SPARKLINE_HEIGHT - PLOT_INSET_TOP - PLOT_INSET_BOTTOM;

  const points = values.map((value, index) => {
    if (value === null) {
      return null;
    }

    // A band with no width means every day carried the same number. Pinning
    // those to an edge would draw the flat line this scaling exists to avoid,
    // and at the bottom it would be invisible; centred, it reads as what it
    // is.
    const fraction = 0 === range ? 0.5 : Math.min(1, Math.max(0, (value - scaleLow) / range));

    return [index * step, SPARKLINE_HEIGHT - PLOT_INSET_BOTTOM - fraction * plotHeight];
  });

  return { points, scaleLow, scaleHigh, bounds: boundsOf(values) };
}

// The two numbers at the top and bottom of the plot area.
//
// They are the bounds the geometry actually maps against, not the series' own
// minimum and maximum. computePlottedSeries() plots against the percentile
// band from scaleBounds(), so a label carrying the raw extremes would invite
// a linear reading that is wrong exactly when the band was needed: with 29
// ordinary days around 100 and one spike at 3000, the band is roughly
// [82, 108] and an ordinary day sits at 71% of the height. Under a "3000" top
// label that day reads as about 2100.
//
// First and last day under the chart. Without them the curve says how the
// numbers moved but not over what, and the period selector sits far enough
// away (above the tiles, or in the widget's settings) to be no answer.
function buildDateAxis(dates) {
  const axis = document.createElement('div');
  axis.className = 'pagemetrics-sparkline-dates';
  axis.setAttribute('aria-hidden', 'true');

  const shown = dates.length > 1 ? [dates[0], dates[dates.length - 1]] : [dates[0]];
  // The year is dropped while the period stays inside one, which is every
  // period this extension offers except one that happens to straddle New
  // Year. Truncated from the left rather than reformatted, so the reading
  // order stays the one every other date in the backend uses.
  const sameYear = shown[0].slice(0, 4) === shown[shown.length - 1].slice(0, 4);

  shown.forEach((date) => {
    const label = document.createElement('span');
    label.textContent = sameYear ? date.slice(5) : date;
    axis.appendChild(label);
  });

  return axis;
}

// The chart's textual equivalent. An SVG of clamped polylines conveys nothing
// at all without sight, and the axis labels are bare numbers in the corners -
// this is the one place the range gets stated as a sentence.
function buildChartSummary(labels, dates, bounds, unit) {
  const summary = (null !== bounds ? labels.chartSummary : labels.chartSummaryDates)
    .replace('%1$s', dates[0])
    .replace('%2$s', dates[dates.length - 1]);

  if (null === bounds) {
    return summary;
  }

  return summary
    .replace('%3$s', formatValueForUnit(bounds[0], unit))
    .replace('%4$s', formatValueForUnit(bounds[1], unit));
}

// One explaining tooltip for the whole file, shared by the popover's figures
// and by the header's fold-away control. Its own element on <body>, because
// the popover clips its overflow and the control that opens it can sit
// anywhere.
//
// The browser's own title attribute would be the obvious answer and is not
// one: it waits about a second, cannot be styled to match the backend, and
// runs the name and the explanation together into a single line.
let hintTooltip = null;
let hintTooltipFor = null;

function showHintTooltip(element, name, hint) {
  if (!hintTooltip) {
    hintTooltip = document.createElement('div');
    hintTooltip.className = 'pagemetrics-stat-tooltip';
    hintTooltip.setAttribute('aria-hidden', 'true');
    document.body.appendChild(hintTooltip);
  }

  hintTooltip.replaceChildren();

  const title = document.createElement('span');
  title.className = 'pagemetrics-stat-tooltip-title';
  title.textContent = name;
  hintTooltip.appendChild(title);

  if ('' !== hint) {
    const description = document.createElement('span');
    description.textContent = hint;
    hintTooltip.appendChild(description);
  }

  hintTooltip.hidden = false;
  hintTooltipFor = element;

  const rect = element.getBoundingClientRect();
  const margin = 8;
  const width = hintTooltip.offsetWidth;
  // Above by default, below when there is no room, and always inside the
  // viewport horizontally - the same clamping positionPopover() applies to
  // the popover as a whole.
  const above = rect.top - hintTooltip.offsetHeight - 4;
  hintTooltip.style.top = `${above > margin ? above : rect.bottom + 4}px`;
  hintTooltip.style.left = `${Math.min(Math.max(margin, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - margin)}px`;
}

function hideHintTooltip() {
  if (hintTooltip) {
    hintTooltip.hidden = true;
    hintTooltipFor = null;
  }
}

// Hover and keyboard focus both open it, and a tap toggles it, which is the
// only one of the three a touch device has.
//
// The text is read from the element when the tooltip opens, not captured
// when the handler is bound: the fold-away control means the opposite thing
// after it has been used, and rebinding to swap the words would leave the
// previous pair of listeners attached.
function bindHintTooltip(element) {
  const open = () => showHintTooltip(element, element.dataset.hintTitle || '', element.dataset.hintText || '');

  element.addEventListener('mouseenter', open);
  element.addEventListener('focus', open);
  element.addEventListener('mouseleave', () => hideHintTooltip());
  element.addEventListener('blur', () => hideHintTooltip());
  element.addEventListener('click', () => {
    if (hintTooltipFor === element && hintTooltip && !hintTooltip.hidden) {
      hideHintTooltip();

      return;
    }

    open();
  });
}

// A marker is a tick on its day, or a band across the days it covers. The
// band is the whole point of date_until: a campaign that ran for three weeks
// drawn as a line on the Monday it started says it was over by the evening.
//
// Both carry their category as a class, so the colour comes from the
// stylesheet where it can be a light-dark() pair, rather than from a hex
// string travelling through JSON.
function appendAnnotationMarker(svg, annotation, dates, step) {
  const index = dates.indexOf(annotation.date);
  if (index === -1 && !annotation.dateUntil) {
    return;
  }

  // A band can start before the period does. Clamped to the first day rather
  // than dropped: it is still running, and that is why it is being drawn.
  const fromIndex = index === -1 ? 0 : index;
  const untilIndex = annotation.dateUntil ? dates.indexOf(annotation.dateUntil) : -1;
  const category = annotation.category || 'release';

  if (annotation.dateUntil && untilIndex !== fromIndex) {
    const lastIndex = untilIndex === -1 ? dates.length - 1 : untilIndex;
    const band = document.createElementNS(SVG_NS, 'rect');
    band.setAttribute('x', fromIndex * step);
    // A strip along the floor, not a wash over the whole plot. Full height
    // put a pale rectangle between the reader and the curve across a third
    // of the chart, and the curve is what the chart is for. On the floor it
    // still says "these days" without ever covering a value, which is how a
    // Gantt row or a timeline band reads anyway.
    band.setAttribute('y', String(SPARKLINE_HEIGHT - ANNOTATION_BAND_HEIGHT));
    band.setAttribute('width', Math.max((lastIndex - fromIndex) * step, 1));
    band.setAttribute('height', String(ANNOTATION_BAND_HEIGHT));
    band.setAttribute('class', `pagemetrics-sparkline-annotation-band pagemetrics-annotation-${category}`);
    svg.appendChild(band);

    return;
  }

  const x = fromIndex * step;
  const marker = document.createElementNS(SVG_NS, 'line');
  marker.setAttribute('x1', x);
  marker.setAttribute('x2', x);
  marker.setAttribute('y1', '0');
  marker.setAttribute('y2', String(SPARKLINE_HEIGHT));
  marker.setAttribute('class', `pagemetrics-sparkline-annotation pagemetrics-annotation-${category}`);
  marker.setAttribute('vector-effect', 'non-scaling-stroke');
  svg.appendChild(marker);
}

// The tooltip line for whichever annotation explains a given day, naming
// the kind of event before what happened. A band answers for every day it
// spans, not just the one it began on.
//
// The kind is spelled out rather than left to the marker's colour: a
// page-header tile carries no legend, so the colour would otherwise be the
// only thing saying it.
function annotationLineFor(annotations, date, labels) {
  const match = annotations.find((annotation) => {
    if (annotation.dateUntil) {
      return date >= annotation.date && date <= annotation.dateUntil;
    }

    return annotation.date === date;
  });

  if (!match) {
    return undefined;
  }

  const kind = labels[`annotationCategory.${match.category || 'release'}`];

  return kind ? `${kind}: ${match.text}` : match.text;
}

class PageMetricsTile {
  constructor(element) {
    this.element = element;
    this.data = JSON.parse(element.dataset.sparkline || '{}');
    this.labels = getLabels(element);
    this.popover = null;
    element.addEventListener('click', (event) => {
      if (this.popover && this.popover.contains(event.target)) {
        return;
      }
      this.togglePopover();
    });
    element.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.togglePopover();
      }
    });
  }

  // "Typical page: 44%", and for a count metric where this page sits in that
  // field. Deliberately a band and not a percentile: on a site with eleven
  // pages the difference between the 63rd and the 72nd percentile is one page
  // changing its mind, and a number that moves that easily invites a reading
  // it cannot support.
  //
  // The band and the width of the field live in the tooltip rather than in
  // the row, because the row has space for a number and this figure's number
  // is the median. Where the page sits relative to it is the sentence you
  // ask for, not the one you read five times over while scanning the chips.
  benchmarkHint() {
    const hint = this.labels.typicalPageHint.replace('%1$s', this.element.dataset.benchmarkPages || '0');
    const band = this.element.dataset.benchmarkBand;
    const bandLabel = band ? this.labels[`typicalPage${band.charAt(0).toUpperCase()}${band.slice(1)}`] : '';

    return bandLabel ? `${hint}. ${bandLabel}` : hint;
  }

  closePopover() {
    if (!this.popover) {
      return;
    }
    this.popover.remove();
    this.popover = null;
    // Lives on <body>, so closing the popover does not take it with it.
    hideHintTooltip();
    this.element.setAttribute('aria-expanded', 'false');
    openTiles.delete(this);
  }

  positionPopover() {
    if (!this.popover) {
      return;
    }
    const rect = this.element.getBoundingClientRect();
    const margin = 8;
    const maxLeft = window.innerWidth - this.popover.offsetWidth - margin;
    const left = Math.min(rect.left, Math.max(margin, maxLeft));

    this.popover.style.left = `${left}px`;
    this.popover.style.top = `${rect.bottom + 4}px`;
  }

  // What a summary tile's headline is made of, which for the machine-traffic
  // tile is the only way back to which crawler did what. Absent on every
  // other tile, where the headline already stands for exactly one metric and
  // a list of one would say nothing.
  //
  // A description list rather than a table: these are pairs, not a grid, and
  // a table would promise column semantics there are none of.
  buildBreakdown() {
    let parts;
    try {
      parts = JSON.parse(this.element.dataset.breakdown || '[]');
    } catch {
      return null;
    }

    if (!Array.isArray(parts) || parts.length === 0) {
      return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'pagemetrics-popover-breakdown';

    const heading = document.createElement('p');
    heading.className = 'pagemetrics-popover-breakdown-heading';
    heading.textContent = this.labels.breakdown;
    wrapper.appendChild(heading);

    // Each pair in its own wrapper inside the <dl>. WebKit drops list
    // semantics from a description list whose display is not a list value,
    // so the two-column grid has to live on the pairs rather than on the
    // <dl> itself, or a screen reader announces loose text instead of
    // label-and-value.
    const list = document.createElement('dl');

    parts.forEach((part) => {
      const pair = document.createElement('div');
      pair.className = 'pagemetrics-popover-breakdown-pair';

      const label = document.createElement('dt');
      label.textContent = part.label;

      const value = document.createElement('dd');
      value.textContent = part.formattedValue;

      pair.appendChild(label);
      pair.appendChild(value);
      list.appendChild(pair);
    });

    wrapper.appendChild(list);

    return wrapper;
  }

  buildSparklineSvg() {
    const entries = Object.entries(this.data);
    if (entries.length === 0) {
      return null;
    }

    const values = entries.map(([, value]) => value);
    const width = SPARKLINE_WIDTH;
    const step = entries.length > 1 ? width / (entries.length - 1) : 0;
    const plotted = plotAgainstBounds(values, step);
    const points = plotted.points;

    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${SPARKLINE_HEIGHT}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'pagemetrics-sparkline');

    appendSeriesPath(svg, points, { stroke: 'currentColor', withArea: true });

    // Marks for the annotations covering this sparkline's own dates. The
    // same list on every tile of a page, since a marker explains the day
    // rather than the metric. Text is surfaced through the existing
    // mousemove-tracked value tooltip below (see annotationLineFor) rather
    // than a native SVG <title>: a 1px line is a poor, hard-to-hit hover
    // target, and a second, independent tooltip mechanism would fight the
    // one already there.
    const annotations = getAnnotations(this.element);
    const dates = entries.map(([date]) => date);

    // The x position only depends on the index, so an annotation still gets
    // its mark on a day the metric itself has no value for.
    annotations.forEach((annotation) => appendAnnotationMarker(svg, annotation, dates, step));

    // An HTML element rather than an SVG <circle>: the SVG uses
    // preserveAspectRatio="none" so the line fills the full width/height,
    // but that also stretches SVG shapes non-uniformly - a native circle
    // would render as a squashed oval. Positioned in pixel-space instead,
    // matching the tooltip's own math, so it stays a true circle.
    const marker = document.createElement('div');
    marker.className = 'pagemetrics-sparkline-marker';
    marker.hidden = true;

    const tooltip = document.createElement('div');
    tooltip.className = 'pagemetrics-sparkline-tooltip';
    tooltip.hidden = true;

    // Two separate lines rather than one run-on string: the date/value is
    // always short and must stay on one line, while an annotation's text is
    // free-form and needs to wrap - mixing both in one nowrap-vs-normal
    // element produces an unpredictably narrow, cramped box (a tooltip
    // <div> with no explicit width shrinks-to-fit under position:absolute).
    const tooltipValue = document.createElement('div');
    tooltipValue.className = 'pagemetrics-sparkline-tooltip-value';
    tooltip.appendChild(tooltipValue);

    const tooltipAnnotation = document.createElement('div');
    tooltipAnnotation.className = 'pagemetrics-sparkline-tooltip-annotation';
    tooltip.appendChild(tooltipAnnotation);

    svg.addEventListener('mousemove', (event) => {
      const rect = svg.getBoundingClientRect();
      const relativeX = ((event.clientX - rect.left) / rect.width) * width;
      const index = Math.max(0, Math.min(entries.length - 1, step > 0 ? Math.round(relativeX / step) : 0));
      const [date, value] = entries[index];
      const point = points[index];

      const centerX = (index * step / width) * rect.width;
      // A day with no data has no point to put the marker on, so the marker
      // is hidden and the tooltip is anchored mid-height instead.
      const centerY = point === null ? rect.height / 2 : (point[1] / SPARKLINE_HEIGHT) * rect.height;

      marker.style.left = `${centerX}px`;
      marker.style.top = `${centerY}px`;
      marker.hidden = point === null;

      const annotationText = annotationLineFor(annotations, date, this.labels);
      tooltipValue.textContent = `${date}: ${value === null ? this.labels.noData : formatValueForUnit(value, this.element.dataset.unit)}`;
      tooltipAnnotation.textContent = annotationText ?? '';
      tooltipAnnotation.hidden = !annotationText;
      tooltip.hidden = false;
      tooltip.style.left = `${centerX}px`;
      tooltip.style.top = `${centerY}px`;

      // Below the cursor by default (see .pagemetrics-sparkline-tooltip),
      // but the popover has no room to spare - a hover point near the
      // chart's bottom edge pushes the tooltip past the popover's own
      // bottom, where overflow:hidden would clip it. Flip to above only
      // when that would actually happen, mirroring positionPopover()'s own
      // viewport-edge clamping for the popover as a whole.
      //
      // Always measured from the default "below" state, never from
      // whatever class the previous mousemove event left behind - checking
      // against last frame's already-flipped position turns this into a
      // feedback loop (each frame's verdict based on the other position),
      // which reads as the tooltip flickering between above/below on every
      // move instead of settling.
      tooltip.classList.remove('pagemetrics-sparkline-tooltip-above');
      // Measured against the plot area rather than the popover: the popover
      // has content below the chart, so comparing against its bottom would
      // let the tooltip spill over that content instead of flipping.
      const overflowsBelow = tooltip.getBoundingClientRect().bottom > svg.getBoundingClientRect().bottom;
      tooltip.classList.toggle('pagemetrics-sparkline-tooltip-above', overflowsBelow);

      const halfTooltipWidth = tooltip.offsetWidth / 2;
      const clampedX = Math.min(Math.max(centerX, halfTooltipWidth), rect.width - halfTooltipWidth);
      tooltip.style.left = `${clampedX}px`;
    });

    svg.addEventListener('mouseleave', () => {
      marker.hidden = true;
      tooltip.hidden = true;
    });

    const wrapper = document.createElement('div');
    wrapper.className = 'pagemetrics-sparkline-wrapper';
    wrapper.setAttribute('role', 'img');
    wrapper.setAttribute('aria-label', buildChartSummary(this.labels, entries.map(([date]) => date), plotted.bounds, this.element.dataset.unit));
    wrapper.appendChild(svg);
    wrapper.appendChild(marker);
    wrapper.appendChild(tooltip);

    return wrapper;
  }

  togglePopover() {
    if (this.popover) {
      this.closePopover();
      return;
    }

    openTiles.forEach((tile) => tile.closePopover());

    // The last two days of the selected period, which is not the same as
    // today and yesterday: the import marks a day complete only once it has
    // elapsed, so the final point is routinely still empty, and after a
    // stalled scheduler it can be much older. Calling these "Today" and
    // "Yesterday" claimed a freshness the pipeline does not have.
    //
    // They are positions, so they read the series as it is - filtering the
    // empty days out first would silently relabel whichever two days happen
    // to carry data.
    const allValues = Object.values(this.data);
    const lastDay = allValues.at(-1) ?? null;
    const previousDay = allValues.at(-2) ?? null;

    // Peak and average, by contrast, are statements about the data that
    // exists: counting an uncovered day as zero would drag the average down
    // and report a peak of 0 for a period with no numbers in it at all.
    const values = allValues.filter((value) => value !== null);
    const peak = values.length > 0 ? Math.max(...values) : null;
    const average = values.length > 0 ? Math.round(values.reduce((sum, value) => sum + value, 0) / values.length) : null;
    const redirectShare = parseInt(this.element.dataset.redirectShare || '0', 10);
    const unit = this.element.dataset.unit;

    this.popover = document.createElement('div');
    this.popover.className = 'pagemetrics-popover';
    // --pm-accent is set inline on the tile and normally inherits down the
    // DOM tree, but the popover is appended to <body> (see below) to escape
    // the tile row's scroll clipping - that detaches it from the tile's
    // ancestor chain, so the custom property has to be copied over explicitly.
    this.popover.style.setProperty('--pm-accent', this.element.style.getPropertyValue('--pm-accent'));

    const heading = document.createElement('div');
    heading.className = 'pagemetrics-popover-heading';

    const headingLabel = document.createElement('span');
    headingLabel.textContent = this.element.dataset.label || '';
    heading.appendChild(headingLabel);

    if (this.element.dataset.externalLink) {
      const externalLink = document.createElement('a');
      externalLink.className = 'pagemetrics-popover-external-link';
      externalLink.href = this.element.dataset.externalLink;
      externalLink.target = '_blank';
      externalLink.rel = 'noopener noreferrer';
      externalLink.title = this.labels.externalLink;
      externalLink.setAttribute('aria-label', this.labels.externalLink);
      externalLink.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" />' +
        '<path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>';
      heading.appendChild(externalLink);
    }

    this.popover.appendChild(heading);

    if (this.element.dataset.description) {
      const description = document.createElement('p');
      description.className = 'pagemetrics-popover-description';
      description.textContent = this.element.dataset.description;
      this.popover.appendChild(description);
    }

    // An icon carries each stat, with its name and its explanation in text
    // that is there but not drawn. Five wrapping word pairs did not fit the
    // row, and the fix is not to hide the meaning: the hidden text is the
    // element's accessible name, so a screen reader reads "Peak. The highest
    // value a single day reached. 162" rather than a bare number.
    //
    // A button, not a div: the explanation has to be reachable without a
    // mouse. Hover shows it, so does keyboard focus, and a tap toggles it,
    // which is the only one of the three a touch device has.
    //
    // Built as nodes rather than an innerHTML string for the text: the
    // labels come from the XLF, which a language pack or a
    // locallangXMLOverride can replace, and that must not be able to inject
    // markup here. Only the icon, whose path data is a constant in this
    // file, is set as markup.
    const stat = (key, value, hintOverride = null) => {
      const name = this.labels[key];
      const hint = hintOverride ?? this.labels[`${key}Hint`] ?? '';

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'pagemetrics-popover-stat';

      const icon = document.createElement('span');
      icon.className = 'pagemetrics-popover-stat-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = statIconSvg(STAT_ICON_PATHS[key]);

      const labelElement = document.createElement('span');
      labelElement.className = 'pagemetrics-popover-stat-label';
      labelElement.textContent = '' !== hint ? `${name}. ${hint}.` : name;

      const valueElement = document.createElement('span');
      valueElement.className = 'pagemetrics-popover-stat-value';
      valueElement.textContent = value === null ? this.labels.noData : value;

      button.appendChild(icon);
      button.appendChild(labelElement);
      button.appendChild(valueElement);

      button.dataset.hintTitle = name;
      button.dataset.hintText = hint;
      bindHintTooltip(button);

      return button;
    };

    const formatted = (value) => (value === null ? null : formatValueForUnit(value, unit));

    const stats = document.createElement('div');
    stats.className = 'pagemetrics-popover-stats';
    const statNodes = [
      stat('lastDay', formatted(lastDay)),
      stat('previousDay', formatted(previousDay)),
      stat('peak', formatted(peak)),
      // For a rate tile this is the simple mean of the daily rates, which
      // can differ from the tile's own headline value (a weighted average:
      // sum of numerator / sum of denominator over the period) on a page
      // with uneven day-to-day traffic - that divergence is itself useful
      // information, not redundant with the headline number.
      stat('average', formatted(average)),
    ];

    if (redirectShare > 0) {
      statNodes.push(stat('viaRedirects', `${redirectShare}%`));
    }

    // Last in the row, and the only chip that is about the other pages of the
    // site rather than about this one over the period.
    const benchmarkMedian = this.element.dataset.benchmarkMedian;
    if (benchmarkMedian) {
      statNodes.push(stat('typicalPage', benchmarkMedian, this.benchmarkHint()));
    }

    statNodes.forEach((node) => stats.appendChild(node));
    this.popover.appendChild(stats);

    const breakdown = this.buildBreakdown();
    if (breakdown) {
      this.popover.appendChild(breakdown);
    }

    const sparkline = this.buildSparklineSvg();
    if (sparkline) {
      // The dates go above the chart, not below it, so the chart closes the
      // popover flush at its bottom edge. They are a caption here rather
      // than a tick axis, and they line up with the heading, description and
      // stats column above them instead of with the full-bleed plot area.
      //
      // A sibling of the wrapper either way: the wrapper is the positioning
      // context for the marker, the tooltip and the value axis, all of which
      // span it edge to edge, so anything in its normal flow other than the
      // SVG itself would move those off the plot area.
      this.popover.appendChild(buildDateAxis(Object.keys(this.data)));
      this.popover.appendChild(sparkline);
    }

    // Appended to <body> rather than the tile itself: the tile row scrolls
    // horizontally (overflow-x: auto for the slider), and per the CSS spec
    // an axis with non-visible overflow forces the other axis to compute as
    // auto too - a popover positioned relative to the tile would be clipped
    // by that implied vertical clipping. Positioned via getBoundingClientRect()
    // instead of relying on CSS layout relative to its old parent.
    document.body.appendChild(this.popover);
    this.positionPopover();
    this.element.setAttribute('aria-expanded', 'true');
    openTiles.add(this);
  }
}

function initializeTiles(container) {
  container.querySelectorAll('.pagemetrics-tile').forEach((element) => new PageMetricsTile(element));
}

const sliderUpdaters = new Set();

function initializeSlider(container) {
  const tiles = container.querySelector('.pagemetrics-tiles');
  if (!tiles) {
    return;
  }

  const labels = getLabels(tiles);

  const track = document.createElement('div');
  track.className = 'pagemetrics-slider';
  tiles.parentNode.insertBefore(track, tiles);

  const prevButton = document.createElement('button');
  prevButton.type = 'button';
  prevButton.className = 'pagemetrics-slider-arrow pagemetrics-slider-arrow-prev';
  prevButton.setAttribute('aria-label', labels.sliderPrevious);
  prevButton.innerHTML = chevronSvg(CHEVRON_LEFT_PATH);
  track.appendChild(prevButton);
  track.appendChild(tiles);

  const nextButton = document.createElement('button');
  nextButton.type = 'button';
  nextButton.className = 'pagemetrics-slider-arrow pagemetrics-slider-arrow-next';
  nextButton.setAttribute('aria-label', labels.sliderNext);
  nextButton.innerHTML = chevronSvg(CHEVRON_RIGHT_PATH);
  track.appendChild(nextButton);

  const dots = document.createElement('div');
  dots.className = 'pagemetrics-slider-dots';
  track.parentNode.insertBefore(dots, track.nextSibling);

  const getMaxScrollLeft = () => Math.max(0, tiles.scrollWidth - tiles.clientWidth);

  const updateFadeMask = () => {
    const maxScrollLeft = getMaxScrollLeft();
    const canScrollLeft = tiles.scrollLeft > 0;
    const canScrollRight = tiles.scrollLeft < maxScrollLeft - 1;

    if (!canScrollLeft && !canScrollRight) {
      tiles.style.maskImage = '';
      tiles.style.webkitMaskImage = '';
      return;
    }

    const stops = [
      canScrollLeft ? 'transparent 0%' : 'black 0%',
      ...(canScrollLeft ? ['black 24px'] : []),
      ...(canScrollRight ? ['black calc(100% - 24px)'] : []),
      canScrollRight ? 'transparent 100%' : 'black 100%',
    ];
    const mask = `linear-gradient(to right, ${stops.join(', ')})`;
    tiles.style.maskImage = mask;
    tiles.style.webkitMaskImage = mask;
  };

  const updateActiveState = () => {
    const maxScrollLeft = getMaxScrollLeft();
    const dotElements = dots.querySelectorAll('.pagemetrics-slider-dot');
    // Scroll-snap pulls scrollLeft to the nearest tile edge, not to clean
    // page-width multiples, so pages are tracked as a proportion of the
    // total scrollable distance rather than scrollLeft / pageWidth.
    const activePage =
      maxScrollLeft > 0 && dotElements.length > 1
        ? Math.round((tiles.scrollLeft / maxScrollLeft) * (dotElements.length - 1))
        : 0;
    dotElements.forEach((dot, index) => {
      dot.classList.toggle('pagemetrics-slider-dot-active', index === activePage);
    });
    prevButton.disabled = tiles.scrollLeft <= 0;
    nextButton.disabled = tiles.scrollLeft >= maxScrollLeft - 1;
    updateFadeMask();
  };

  const updatePages = () => {
    if (!tiles.isConnected) {
      sliderUpdaters.delete(updatePages);
      return;
    }

    const pageWidth = tiles.clientWidth;
    const maxScrollLeft = getMaxScrollLeft();
    const pageCount = pageWidth > 0 ? Math.ceil(tiles.scrollWidth / pageWidth) : 1;
    const hasOverflow = pageCount > 1;

    prevButton.hidden = !hasOverflow;
    nextButton.hidden = !hasOverflow;
    dots.hidden = !hasOverflow;
    dots.innerHTML = '';

    if (!hasOverflow) {
      tiles.style.maskImage = '';
      tiles.style.webkitMaskImage = '';
      return;
    }

    for (let page = 0; page < pageCount; page += 1) {
      const dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'pagemetrics-slider-dot';
      dot.setAttribute('aria-label', labels.sliderPage.replace('%1$s', String(page + 1)));
      dot.addEventListener('click', () => {
        tiles.scrollTo({ left: maxScrollLeft * (page / (pageCount - 1)), behavior: 'smooth' });
      });
      dots.appendChild(dot);
    }

    updateActiveState();
  };

  prevButton.addEventListener('click', () => {
    tiles.scrollBy({ left: -tiles.clientWidth, behavior: 'smooth' });
  });
  nextButton.addEventListener('click', () => {
    tiles.scrollBy({ left: tiles.clientWidth, behavior: 'smooth' });
  });
  tiles.addEventListener('scroll', () => {
    window.requestAnimationFrame(updateActiveState);
  });

  sliderUpdaters.add(updatePages);
  updatePages();
}

function bindPeriodLinks(container) {
  container.querySelectorAll('.pagemetrics-period-link').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      loadTiles(container, link.dataset.period, container.dataset.includeSubpages === '1');
    });
  });
}

// menu -> its own trigger, so the global close handlers below never have to
// guess the relationship from DOM position/order.
const openOptionsMenus = new Map();

function closeOptionsMenu(menu) {
  const trigger = openOptionsMenus.get(menu);
  if (!trigger) {
    return;
  }
  menu.hidden = true;
  trigger.setAttribute('aria-expanded', 'false');
  trigger.classList.remove('pagemetrics-options-trigger-active');
  openOptionsMenus.delete(menu);
}

function bindOptionsMenu(container) {
  const trigger = container.querySelector('[data-options-trigger]');
  const menu = container.querySelector('[data-options-menu]');
  if (!trigger || !menu) {
    return;
  }

  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();

    if (!menu.hidden) {
      closeOptionsMenu(menu);
      return;
    }

    openOptionsMenus.forEach((_openTrigger, openMenu) => closeOptionsMenu(openMenu));

    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    trigger.classList.add('pagemetrics-options-trigger-active');
    openOptionsMenus.set(menu, trigger);
  });

  const toggle = menu.querySelector('[data-include-subpages-toggle]');
  if (toggle) {
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      closeOptionsMenu(menu);
      loadTiles(container, container.dataset.period, container.dataset.includeSubpages !== '1');
    });
  }
}

document.addEventListener('click', (event) => {
  openOptionsMenus.forEach((trigger, menu) => {
    if (!menu.contains(event.target) && !trigger.contains(event.target)) {
      closeOptionsMenu(menu);
    }
  });
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') {
    return;
  }
  openOptionsMenus.forEach((trigger, menu) => {
    closeOptionsMenu(menu);
    trigger.focus();
  });
});

const DEFAULT_SKELETON_TILE_COUNT = 4;
// Kept in sync with the real .pagemetrics-tile width/gap in page-metrics.css.
const SKELETON_TILE_WIDTH = 210;
const SKELETON_TILE_GAP = 12;

function buildLoadingSkeletonHtml(count) {
  const tile =
    '<div class="pagemetrics-skeleton-tile">' +
    '<div class="pagemetrics-skeleton-icon"></div>' +
    '<div class="pagemetrics-skeleton-lines">' +
    '<div class="pagemetrics-skeleton-line pagemetrics-skeleton-line-value"></div>' +
    '<div class="pagemetrics-skeleton-line pagemetrics-skeleton-line-label"></div>' +
    '</div>' +
    '</div>';
  return `<div class="pagemetrics-skeleton">${tile.repeat(count)}</div>`;
}

function buildSliderChromeSkeletonHtml(pageCount) {
  const arrowSvg = (path) =>
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="${path}" /></svg>`;
  const prevArrow = `<span class="pagemetrics-slider-arrow" aria-hidden="true">${arrowSvg('M15 6l-6 6l6 6')}</span>`;
  const nextArrow = `<span class="pagemetrics-slider-arrow" aria-hidden="true">${arrowSvg('M9 6l6 6l-6 6')}</span>`;
  const dots = Array.from(
    { length: pageCount },
    (_, index) => `<span class="pagemetrics-slider-dot${index === 0 ? ' pagemetrics-slider-dot-active' : ''}"></span>`,
  ).join('');

  return {
    prevArrow,
    nextArrow,
    dotsRow: `<div class="pagemetrics-slider-dots" aria-hidden="true">${dots}</div>`,
  };
}

function buildInitialLoadingSkeletonHtml(count, availableWidth) {
  const tilesHtml = buildLoadingSkeletonHtml(count);
  // The period-filter nav doesn't exist yet on the very first load, so its
  // own placeholder is needed here too - otherwise the real content arrives
  // taller than the skeleton and everything below jumps down.
  const periodHtml = '<div class="pagemetrics-period-skeleton"></div>';

  const totalWidth = count * SKELETON_TILE_WIDTH + (count - 1) * SKELETON_TILE_GAP;
  const hasOverflow = availableWidth > 0 && totalWidth > availableWidth;

  if (!hasOverflow) {
    return periodHtml + tilesHtml;
  }

  // Enough tiles to need the real slider once loaded - show its arrows/dots
  // as static placeholders too, so that chrome doesn't pop in and shift the
  // layout once the actual tiles (and initializeSlider()) replace this.
  const pageCount = Math.max(2, Math.ceil(totalWidth / availableWidth));
  const { prevArrow, nextArrow, dotsRow } = buildSliderChromeSkeletonHtml(pageCount);

  return `${periodHtml}<div class="pagemetrics-slider">${prevArrow}${tilesHtml}${nextArrow}</div>${dotsRow}`;
}

async function loadTiles(container, period, includeSubpages) {
  const previousHtml = container.innerHTML;
  const tilesContainer = container.querySelector('.pagemetrics-tiles');

  // closePopover() (not a bare openTiles.delete()) so the popover element -
  // appended to <body>, detached from the tile itself - is actually removed
  // instead of being orphaned once the tile underneath it is replaced by
  // the loading skeleton below.
  openTiles.forEach((tile) => {
    if (container.contains(tile.element)) {
      tile.closePopover();
    }
  });

  // On a period switch the nav and hint rows already exist - only swap the
  // tiles themselves for the skeleton so the surrounding layout doesn't
  // collapse and reappear, and match the skeleton's tile count to what is
  // already showing. On the very first load nothing exists yet, so the
  // skeleton takes over the whole placeholder with a reasonable default.
  if (tilesContainer) {
    const currentTileCount = tilesContainer.querySelectorAll('.pagemetrics-tile').length || DEFAULT_SKELETON_TILE_COUNT;
    tilesContainer.innerHTML = buildLoadingSkeletonHtml(currentTileCount);
  } else {
    container.innerHTML = buildInitialLoadingSkeletonHtml(DEFAULT_SKELETON_TILE_COUNT, container.clientWidth);
  }

  const url = new URL(container.dataset.ajaxUrl, window.location.origin);
  url.searchParams.set('id', container.dataset.pageId);
  url.searchParams.set('period', period);
  url.searchParams.set('includeSubpages', includeSubpages ? '1' : '0');

  let html;
  try {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) {
      container.innerHTML = previousHtml;
      return;
    }
    html = await response.text();
  } catch {
    container.innerHTML = previousHtml;
    return;
  }

  container.innerHTML = html;
  container.dataset.period = period;
  container.dataset.includeSubpages = includeSubpages ? '1' : '0';
  initializeTiles(container);
  bindPeriodLinks(container);
  bindOptionsMenu(container);
  initializeSlider(container);
}

document.addEventListener('click', (event) => {
  openTiles.forEach((tile) => {
    const insideTile = tile.element.contains(event.target);
    const insidePopover = tile.popover && tile.popover.contains(event.target);
    if (!insideTile && !insidePopover) {
      tile.closePopover();
    }
  });
});

window.addEventListener('resize', () => {
  sliderUpdaters.forEach((updatePages) => updatePages());
  openTiles.forEach((tile) => tile.positionPopover());
});

// The popover is fixed-positioned and detached from the tile row's own
// scroll container, so it does not move with it - close it instead of
// leaving it visually orphaned. Capture phase catches scrolling on nested
// containers (like the tile slider) too, since scroll events don't bubble.
window.addEventListener(
  'scroll',
  () => {
    openTiles.forEach((tile) => tile.closePopover());
  },
  { capture: true },
);

// Folding the block away is a standing preference, so it is stored on the
// backend user rather than in the URL, and the server has already decided
// what to render: a collapsed header arrives with no placeholder at all and
// never issues the request. Expanding builds the placeholder here, which is
// the only moment the client knows more than the server did.
//
// Delegated from the document rather than bound to the button: the header is
// re-rendered on every period switch, and a bound handler would go with it.
// The same explaining tooltip the popover's figures use, so the control that
// hides the whole block says what it does before it is pressed rather than
// after. Re-bound after every render, since the header is replaced wholesale.
function bindCollapseTooltip(root) {
  root.querySelectorAll('[data-collapse-toggle]:not([data-hint-bound])').forEach((toggle) => {
    toggle.dataset.hintBound = '1';
    bindHintTooltip(toggle);
  });
}

bindCollapseTooltip(document);

document.addEventListener('click', (event) => {
  const toggle = event.target.closest('[data-collapse-toggle]');
  if (!toggle) {
    return;
  }

  const header = toggle.closest('.pagemetrics-header');
  if (!header) {
    return;
  }

  const collapsed = !header.classList.contains('pagemetrics-header-collapsed');
  header.classList.toggle('pagemetrics-header-collapsed', collapsed);
  toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

  // The control now does the opposite of what it did a moment ago, so its
  // name and its explanation have to swap with it.
  const swapped = { title: toggle.dataset.hintTitleAlt, text: toggle.dataset.hintTextAlt };
  toggle.dataset.hintTitleAlt = toggle.dataset.hintTitle;
  toggle.dataset.hintTextAlt = toggle.dataset.hintText;
  toggle.dataset.hintTitle = swapped.title || '';
  toggle.dataset.hintText = swapped.text || '';
  toggle.setAttribute('aria-label', toggle.dataset.hintTitle);
  hideHintTooltip();

  if (collapsed) {
    // Only the body goes. The toggle is a sibling, so it stays exactly where
    // it was and expanding is available immediately rather than after a
    // reload.
    header.querySelector('.pagemetrics-header-placeholder')?.remove();
  } else {
    const placeholder = document.createElement('div');
    placeholder.id = header.dataset.bodyId || '';
    placeholder.className = 'pagemetrics-header-placeholder';
    placeholder.dataset.pageId = header.dataset.pageId;
    placeholder.dataset.period = '30';
    placeholder.dataset.includeSubpages = '0';
    placeholder.dataset.ajaxUrl = header.dataset.ajaxUrl;
    header.insertBefore(placeholder, toggle);
    loadTiles(placeholder, '30', false);
  }

  // Fire and forget: the state is already applied, and a preference that
  // fails to store is worth less than blocking the interaction on it. The
  // next page load simply shows the previous choice.
  const body = new FormData();
  body.append('collapsed', collapsed ? '1' : '0');
  fetch(header.dataset.preferencesUrl, { method: 'POST', body, credentials: 'same-origin' }).catch(() => {});
});


document.querySelectorAll('.pagemetrics-header-placeholder').forEach((container) => {
  loadTiles(container, container.dataset.period || '30', container.dataset.includeSubpages === '1');
});

// Linear-interpolated percentile of an already-sorted array, used by
// scaleBounds() below to find a robust (outlier-tolerant) [low, high] pair
// instead of the plain min/max - see the comment there for why.
function percentile(sortedValues, p) {
  if (sortedValues.length === 1) {
    return sortedValues[0];
  }

  const index = p * (sortedValues.length - 1);
  const lower = Math.floor(index);
  const upper = Math.ceil(index);

  return sortedValues[lower] + (sortedValues[upper] - sortedValues[lower]) * (index - lower);
}

// The 5th/95th percentile rather than the plain min/max: a single extreme
// day (most often "today", still an incomplete/partial import - a rate
// metric like "Avg. time on page" falls back to 0 when its denominator is
// still 0, see RateSeriesCalculator::ratio()) would
// otherwise stretch that one outlier across the whole chart height and
// squash every other, actually-typical day into a barely-visible sliver at
// the opposite end. Values outside the percentile band still get plotted -
// see the y-coordinate clamp below - just clipped to the top/bottom edge
// instead of driving the whole scale.
function scaleBounds(values) {
  if (values.length === 0) {
    return [0, 1];
  }

  const sorted = [...values].sort((a, b) => a - b);
  const low = percentile(sorted, 0.05);
  const high = percentile(sorted, 0.95);

  // Degenerates back to the plain min/max when the percentile band is flat
  // (e.g. very short periods, or a series that barely varies) - a zero
  // range would otherwise hide every point at the same edge.
  return low === high ? [sorted[0], sorted[sorted.length - 1]] : [low, high];
}

// Each series is scaled against its own range rather than a shared
// [0, max]: metrics like "Visits" and "Bounce rate" live on wildly
// different scales, and overlaying them is about comparing shape/trend
// over time, not reading them off one shared axis. For a single series
// this is the same tight-range behaviour the chart always had, just
// outlier-tolerant now (see scaleBounds()).
function computePlottedSeries(seriesList, dates, step) {
  return seriesList.map((series) => {
    const values = dates.map((date) => series.values[date] ?? null);

    return { ...series, values, ...plotAgainstBounds(values, step) };
  });
}

function renderSeriesLines(svg, plotted) {
  const isMultiSeries = plotted.length > 1;

  plotted.forEach((series) => {
    appendSeriesPath(svg, series.points, {
      // A single series still relies on the surrounding --pm-accent CSS
      // custom property (currentColor) rather than its own color, so it
      // keeps picking up the compact/trend-sentiment color overrides other
      // rules apply to that property (see page-metrics.css) instead of only
      // ever drawing in the metric's own fixed color.
      stroke: isMultiSeries ? series.color : 'currentColor',
      // Overlaid, a fill would just muddy the overlap between series, so it
      // stays with the single-metric case.
      withArea: !isMultiSeries,
    });
  });
}

// Same annotation marks as the tile sparkline (see buildSparklineSvg()
// above) - the list is embedded on this same element rather than looked up
// via getAnnotations()/closest(), since this chart has no surrounding
// .pagemetrics-tiles container. Returns the parsed list so the tooltip
// (built later, see attachChartInteractivity()) can find the hovered day's
// annotation without re-parsing it.
function renderAnnotationMarkers(svg, container, dates, step) {
  const annotations = JSON.parse(container.dataset.annotations || '[]');

  annotations.forEach((annotation) => appendAnnotationMarker(svg, annotation, dates, step));

  return annotations;
}

// One position marker per series - with a single series this is exactly
// the same lone dot the chart always drew.
function buildSeriesMarkers(plotted) {
  const isMultiSeries = plotted.length > 1;

  return plotted.map((series) => {
    const marker = document.createElement('div');
    marker.className = 'pagemetrics-sparkline-marker';
    marker.hidden = true;
    if (isMultiSeries) {
      marker.style.background = series.color;
    }
    return marker;
  });
}

// The tooltip's DOM skeleton - a heading (shown only once there's more than
// one series, to carry the hovered date since the per-series rows show
// their label instead - see attachChartInteractivity()), one value row per
// series, and a trailing annotation row. Everything here starts empty and
// is filled in on each mousemove.
function buildTooltip(plotted) {
  const isMultiSeries = plotted.length > 1;

  const tooltip = document.createElement('div');
  tooltip.className = 'pagemetrics-sparkline-tooltip';
  tooltip.hidden = true;

  const heading = document.createElement('div');
  heading.className = 'pagemetrics-sparkline-tooltip-heading';
  heading.hidden = !isMultiSeries;
  tooltip.appendChild(heading);

  const values = plotted.map(() => {
    const row = document.createElement('div');
    row.className = 'pagemetrics-sparkline-tooltip-value';
    tooltip.appendChild(row);
    return row;
  });

  const annotation = document.createElement('div');
  annotation.className = 'pagemetrics-sparkline-tooltip-annotation';
  tooltip.appendChild(annotation);

  return { tooltip, heading, values, annotation };
}

// Wires up hover interactivity: on mousemove, positions every series'
// marker and fills in the shared tooltip for whichever date the cursor is
// nearest to; on mouseleave, hides all of it again.
function attachChartInteractivity({ svg, container, plotted, markers, tooltipParts, annotations, dates, step, width, labels }) {
  const { tooltip, heading, values: tooltipValues, annotation: tooltipAnnotation } = tooltipParts;
  const isMultiSeries = plotted.length > 1;

  svg.addEventListener('mousemove', (event) => {
    const rect = svg.getBoundingClientRect();
    const relativeX = ((event.clientX - rect.left) / rect.width) * width;
    const index = Math.max(0, Math.min(dates.length - 1, step > 0 ? Math.round(relativeX / step) : 0));
    const date = dates[index];
    const centerX = ((index * step) / width) * rect.width;
    let centerY = rect.height / 2;

    plotted.forEach((series, seriesIndex) => {
      const point = series.points[index];
      // A day with no data has no point to put the marker on, so it is
      // hidden and the tooltip says so instead of showing a made-up zero.
      const markerCenterY = point === null ? rect.height / 2 : (point[1] / SPARKLINE_HEIGHT) * rect.height;
      markers[seriesIndex].style.left = `${centerX}px`;
      markers[seriesIndex].style.top = `${markerCenterY}px`;
      markers[seriesIndex].hidden = point === null;

      const value = series.values[index];
      const tooltipPrefix = isMultiSeries ? series.label : date;
      tooltipValues[seriesIndex].textContent = `${tooltipPrefix}: ${value === null ? labels.noData : formatValueForUnit(value, series.unit)}`;

      if (0 === seriesIndex && point !== null) {
        centerY = markerCenterY;
      }
    });

    heading.textContent = date;

    const annotationText = annotationLineFor(annotations, date, labels);
    tooltipAnnotation.textContent = annotationText ?? '';
    tooltipAnnotation.hidden = !annotationText;
    tooltip.hidden = false;
    tooltip.style.top = `${centerY}px`;

    // Below the cursor by default (see .pagemetrics-sparkline-tooltip),
    // but a hover point near the chart's own bottom edge - the most common
    // case with a multi-series tooltip, which is taller than the
    // single-value one this chart used to only ever show - pushes it past
    // the widget card's bottom, off-screen until the page is scrolled.
    // Flip to above only when that would actually happen, mirroring
    // PageMetricsTile's own popover-edge clamping above.
    //
    // Always measured from the default "below" state, never from whatever
    // class the previous mousemove event left behind - checking against
    // last frame's already-flipped position turns this into a feedback
    // loop (each frame's verdict based on the other position), which reads
    // as the tooltip flickering between above/below on every move instead
    // of settling.
    tooltip.classList.remove('pagemetrics-sparkline-tooltip-above');
    const overflowsBelow = tooltip.getBoundingClientRect().bottom > container.getBoundingClientRect().bottom;
    tooltip.classList.toggle('pagemetrics-sparkline-tooltip-above', overflowsBelow);

    const halfTooltipWidth = tooltip.offsetWidth / 2;
    const clampedX = Math.min(Math.max(centerX, halfTooltipWidth), rect.width - halfTooltipWidth);
    tooltip.style.left = `${clampedX}px`;
  });

  svg.addEventListener('mouseleave', () => {
    markers.forEach((marker) => {
      marker.hidden = true;
    });
    tooltip.hidden = true;
  });
}

// The color-dot + label row shown under the chart once there's more than
// one series to disambiguate - a single series already carries its label
// in the widget's own heading, so there's nothing to add here.
function buildLegend(plotted) {
  if (plotted.length <= 1) {
    return null;
  }

  const legend = document.createElement('div');
  legend.className = 'pagemetrics-sparkline-legend';

  plotted.forEach((series) => {
    const dot = document.createElement('span');
    dot.className = 'pagemetrics-sparkline-legend-dot';
    dot.style.background = series.color;

    const item = document.createElement('div');
    item.className = 'pagemetrics-sparkline-legend-item';
    item.appendChild(dot);
    item.appendChild(document.createTextNode(series.label));
    legend.appendChild(item);
  });

  return legend;
}

// The dashboard chart widget's markup is static (server-rendered, no AJAX
// reload/popover like the tile), so it only needs the same SVG-building
// math as PageMetricsTile.buildSparklineSvg() - not the click/popover
// machinery that math is normally wrapped in. Duplicated rather than
// reused via that method: PageMetricsTile hard-codes a popover as the
// overflow boundary for its hover tooltip, which does not exist here.
function initializeStaticCharts(root) {
  root.querySelectorAll('.pagemetrics-widget-chart:not([data-initialized])').forEach((container) => {
    container.dataset.initialized = 'true';

    const seriesList = JSON.parse(container.dataset.sparkline || '[]');
    const dates = seriesList.length > 0 ? Object.keys(seriesList[0].values) : [];
    if (dates.length === 0) {
      return;
    }

    const width = SPARKLINE_WIDTH * 3;
    const step = dates.length > 1 ? width / (dates.length - 1) : 0;
    const plotted = computePlottedSeries(seriesList, dates, step);

    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${SPARKLINE_HEIGHT}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'pagemetrics-sparkline');
    renderSeriesLines(svg, plotted);
    const annotations = renderAnnotationMarkers(svg, container, dates, step);

    const markers = buildSeriesMarkers(plotted);
    const tooltipParts = buildTooltip(plotted);
    const labels = getLabels(container);
    attachChartInteractivity({ svg, container, plotted, markers, tooltipParts, annotations, dates, step, width, labels });

    // Overlaid series each get their own scale (see computePlottedSeries),
    // so there is no one axis to label - two numbers in the corners would
    // belong to whichever series happened to be first and silently misread
    // the others. The legend tells them apart instead.
    const bounds = plotted.length > 1 ? null : boundsOf(plotted[0].values);

    const wrapper = document.createElement('div');
    wrapper.className = 'pagemetrics-sparkline-wrapper';
    wrapper.setAttribute('role', 'img');
    wrapper.setAttribute('aria-label', buildChartSummary(labels, dates, bounds, plotted[0].unit));
    wrapper.appendChild(svg);
    markers.forEach((marker) => wrapper.appendChild(marker));
    wrapper.appendChild(tooltipParts.tooltip);

    const legend = buildLegend(plotted);
    if (legend) {
      container.appendChild(legend);
    }

    container.appendChild(wrapper);
    container.appendChild(buildDateAxis(dates));
  });
}

initializeStaticCharts(document);

// Dashboard widgets are fetched via AJAX and injected into the DOM well
// after this module's own top-level code has already run once above, so
// each widget's own static content needs its own initialization pass -
// typo3-dashboard-widget dispatches this (bubbling) event once its content
// is in the DOM. Scoped to event.target rather than re-scanning the whole
// document, and the [data-initialized] guard above stops a duplicate pass
// (e.g. a widget refresh) from building the chart twice.
document.addEventListener('typo3:dashboard:widget:content:rendered', (event) => {
  const target = event.target instanceof Element ? event.target : document;
  initializeStaticCharts(target);
  // The "Page metrics" widget's tiles are interactive (see PageMetricsTile
  // above) just like the PageHeader ones - a no-op for every other
  // dashboard widget, since initializeTiles() only ever finds something to
  // do where .pagemetrics-tile elements actually exist.
  initializeTiles(target);
});
