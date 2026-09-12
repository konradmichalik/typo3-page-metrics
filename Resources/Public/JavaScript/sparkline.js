const SVG_NS = 'http://www.w3.org/2000/svg';
const SPARKLINE_HEIGHT = 52;
// Fixed total width regardless of how many days are plotted (7/30/90, per
// the period selector): points get closer together for a longer period
// rather than the popover growing wider. 29 * 4px matches the previous
// fixed 30-day sparkline's width, so the 30-day case looks unchanged.
const SPARKLINE_WIDTH = 29 * 4;
const openTiles = new Set();

// Hard-coded like every other string in this file; they move to the XLF
// together, see the popover-strings issue.
const NO_DATA_LABEL = 'No data';

const CHEVRON_LEFT_PATH = 'M15 6l-6 6l6 6';
const CHEVRON_RIGHT_PATH = 'M9 6l6 6l-6 6';

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
      const areaPoints = [...segment, [lastX, SPARKLINE_HEIGHT], [firstX, SPARKLINE_HEIGHT]];
      area.setAttribute('points', areaPoints.map((point) => point.join(',')).join(' '));
      area.setAttribute('fill', 'currentColor');
      area.setAttribute('fill-opacity', '0.2');
      area.setAttribute('stroke', 'none');
      svg.appendChild(area);
    }

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
  return String(value);
}

class PageMetricsTile {
  constructor(element) {
    this.element = element;
    this.data = JSON.parse(element.dataset.sparkline || '{}');
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

  closePopover() {
    if (!this.popover) {
      return;
    }
    this.popover.remove();
    this.popover = null;
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

  buildSparklineSvg() {
    const entries = Object.entries(this.data);
    if (entries.length === 0) {
      return null;
    }

    const values = entries.map(([, value]) => value);
    const max = Math.max(...values.filter((value) => value !== null), 1);
    const width = SPARKLINE_WIDTH;
    const step = entries.length > 1 ? width / (entries.length - 1) : 0;

    const points = entries.map(([, value], index) =>
      value === null ? null : [index * step, SPARKLINE_HEIGHT - (value / max) * SPARKLINE_HEIGHT],
    );

    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${SPARKLINE_HEIGHT}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'pagemetrics-sparkline');

    appendSeriesPath(svg, points, { stroke: 'currentColor', withArea: true });

    // Minimal, dashed vertical ticks for annotations (e.g. Matomo releases)
    // that fall on one of this sparkline's own dates - same list on every
    // tile/metric, since annotations are site-wide, not per-metric. Text is
    // surfaced through the existing mousemove-tracked value tooltip below
    // (see annotationTextByDate) rather than a native SVG <title>: a 1px
    // line is a poor, hard-to-hit hover target, and a second, independent
    // tooltip mechanism would fight the one already there.
    const annotationTextByDate = new Map(getAnnotations(this.element).map((annotation) => [annotation.date, annotation.text]));

    annotationTextByDate.forEach((text, date) => {
      const index = entries.findIndex(([entryDate]) => entryDate === date);
      if (index === -1) {
        return;
      }

      // The x position only depends on the index, so an annotation still
      // gets its tick on a day the metric itself has no value for.
      const x = index * step;
      const marker = document.createElementNS(SVG_NS, 'line');
      marker.setAttribute('x1', x);
      marker.setAttribute('x2', x);
      marker.setAttribute('y1', '0');
      marker.setAttribute('y2', String(SPARKLINE_HEIGHT));
      marker.setAttribute('class', 'pagemetrics-sparkline-annotation');
      marker.setAttribute('vector-effect', 'non-scaling-stroke');
      svg.appendChild(marker);
    });

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

      const annotationText = annotationTextByDate.get(date);
      tooltipValue.textContent = `${date}: ${value === null ? NO_DATA_LABEL : formatValueForUnit(value, this.element.dataset.unit)}`;
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
      const overflowsBelow = tooltip.getBoundingClientRect().bottom > this.popover.getBoundingClientRect().bottom;
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

    // Today and yesterday are positions in the period, so they read the
    // series as it is - filtering first would silently relabel the last two
    // days that happen to have data as "today" and "yesterday".
    const allValues = Object.values(this.data);
    const today = allValues.at(-1) ?? null;
    const yesterday = allValues.at(-2) ?? null;

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
      externalLink.title = 'Open in Matomo';
      externalLink.setAttribute('aria-label', 'Open in Matomo');
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

    const stat = (label, value) =>
      `<div class="pagemetrics-popover-stat"><span class="pagemetrics-popover-stat-label">${label}</span><span class="pagemetrics-popover-stat-value">${value === null ? NO_DATA_LABEL : value}</span></div>`;

    const formatted = (value) => (value === null ? null : formatValueForUnit(value, unit));

    const stats = document.createElement('div');
    stats.className = 'pagemetrics-popover-stats';
    stats.innerHTML =
      stat('Today', formatted(today)) +
      stat('Yesterday', formatted(yesterday)) +
      stat('Peak', formatted(peak)) +
      // For a rate tile this is the simple mean of the daily rates, which
      // can differ from the tile's own headline value (a weighted average:
      // sum of numerator / sum of denominator over the period) on a page
      // with uneven day-to-day traffic - that divergence is itself useful
      // information, not redundant with the headline number.
      stat('Average', formatted(average)) +
      (redirectShare > 0 ? stat('Via redirects', `${redirectShare}%`) : '');
    this.popover.appendChild(stats);

    const sparkline = this.buildSparklineSvg();
    if (sparkline) {
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

  const track = document.createElement('div');
  track.className = 'pagemetrics-slider';
  tiles.parentNode.insertBefore(track, tiles);

  const prevButton = document.createElement('button');
  prevButton.type = 'button';
  prevButton.className = 'pagemetrics-slider-arrow pagemetrics-slider-arrow-prev';
  prevButton.setAttribute('aria-label', 'Previous');
  prevButton.innerHTML = chevronSvg(CHEVRON_LEFT_PATH);
  track.appendChild(prevButton);
  track.appendChild(tiles);

  const nextButton = document.createElement('button');
  nextButton.type = 'button';
  nextButton.className = 'pagemetrics-slider-arrow pagemetrics-slider-arrow-next';
  nextButton.setAttribute('aria-label', 'Next');
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
      dot.setAttribute('aria-label', `Page ${page + 1}`);
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
    const [low, high] = scaleBounds(values.filter((value) => value !== null));
    const range = high - low || 1;
    const points = values.map((value, index) => {
      if (value === null) {
        return null;
      }
      const fraction = Math.min(1, Math.max(0, (value - low) / range));
      return [index * step, SPARKLINE_HEIGHT - fraction * SPARKLINE_HEIGHT];
    });
    return { ...series, values, points };
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
      // The area fill only makes sense for a single line - overlaid, it
      // would just muddy the overlap between series - so it's kept for the
      // (still most common) single-metric case only.
      withArea: !isMultiSeries,
    });
  });
}

// Same dashed annotation markers as the tile sparkline (see
// buildSparklineSvg() above) - the list is embedded on this same element
// rather than looked up via getAnnotations()/closest(), since this chart
// has no surrounding .pagemetrics-tiles container. Returns the parsed
// date => text map so the tooltip (built later, see attachChartInteractivity())
// can look up the hovered date's annotation without re-parsing it.
function renderAnnotationMarkers(svg, container, dates, step) {
  const annotationTextByDate = new Map(JSON.parse(container.dataset.annotations || '[]').map((annotation) => [annotation.date, annotation.text]));

  annotationTextByDate.forEach((text, date) => {
    const index = dates.indexOf(date);
    if (index === -1) {
      return;
    }

    const x = index * step;
    const marker = document.createElementNS(SVG_NS, 'line');
    marker.setAttribute('x1', x);
    marker.setAttribute('x2', x);
    marker.setAttribute('y1', '0');
    marker.setAttribute('y2', String(SPARKLINE_HEIGHT));
    marker.setAttribute('class', 'pagemetrics-sparkline-annotation');
    marker.setAttribute('vector-effect', 'non-scaling-stroke');
    svg.appendChild(marker);
  });

  return annotationTextByDate;
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
function attachChartInteractivity({ svg, container, plotted, markers, tooltipParts, annotationTextByDate, dates, step, width }) {
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
      tooltipValues[seriesIndex].textContent = `${tooltipPrefix}: ${value === null ? NO_DATA_LABEL : formatValueForUnit(value, series.unit)}`;

      if (0 === seriesIndex && point !== null) {
        centerY = markerCenterY;
      }
    });

    heading.textContent = date;

    const annotationText = annotationTextByDate.get(date);
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
    const annotationTextByDate = renderAnnotationMarkers(svg, container, dates, step);

    const markers = buildSeriesMarkers(plotted);
    const tooltipParts = buildTooltip(plotted);
    attachChartInteractivity({ svg, container, plotted, markers, tooltipParts, annotationTextByDate, dates, step, width });

    const wrapper = document.createElement('div');
    wrapper.className = 'pagemetrics-sparkline-wrapper';
    wrapper.appendChild(svg);
    markers.forEach((marker) => wrapper.appendChild(marker));
    wrapper.appendChild(tooltipParts.tooltip);

    const legend = buildLegend(plotted);
    if (legend) {
      container.appendChild(legend);
    }

    container.appendChild(wrapper);
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
