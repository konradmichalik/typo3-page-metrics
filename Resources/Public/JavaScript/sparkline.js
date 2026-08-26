const SVG_NS = 'http://www.w3.org/2000/svg';
const SPARKLINE_HEIGHT = 52;
// Fixed total width regardless of how many days are plotted (7/30/90, per
// the period selector): points get closer together for a longer period
// rather than the popover growing wider. 29 * 4px matches the previous
// fixed 30-day sparkline's width, so the 30-day case looks unchanged.
const SPARKLINE_WIDTH = 29 * 4;
const openTiles = new Set();

const CHEVRON_LEFT_PATH = 'M15 6l-6 6l6 6';
const CHEVRON_RIGHT_PATH = 'M9 6l6 6l-6 6';

function chevronSvg(path) {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="${path}" /></svg>`;
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
    const max = Math.max(...values, 1);
    const width = SPARKLINE_WIDTH;
    const step = entries.length > 1 ? width / (entries.length - 1) : 0;

    const points = entries.map(([, value], index) => [
      index * step,
      SPARKLINE_HEIGHT - (value / max) * SPARKLINE_HEIGHT,
    ]);

    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${SPARKLINE_HEIGHT}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'pagemetrics-sparkline');

    const area = document.createElementNS(SVG_NS, 'polygon');
    const areaPoints = [...points, [width, SPARKLINE_HEIGHT], [0, SPARKLINE_HEIGHT]];
    area.setAttribute('points', areaPoints.map((point) => point.join(',')).join(' '));
    area.setAttribute('fill', 'currentColor');
    area.setAttribute('fill-opacity', '0.2');
    area.setAttribute('stroke', 'none');
    svg.appendChild(area);

    const polyline = document.createElementNS(SVG_NS, 'polyline');
    polyline.setAttribute('points', points.map((point) => point.join(',')).join(' '));
    polyline.setAttribute('fill', 'none');
    polyline.setAttribute('stroke', 'currentColor');
    polyline.setAttribute('stroke-width', '1');
    polyline.setAttribute('vector-effect', 'non-scaling-stroke');
    svg.appendChild(polyline);

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

    svg.addEventListener('mousemove', (event) => {
      const rect = svg.getBoundingClientRect();
      const relativeX = ((event.clientX - rect.left) / rect.width) * width;
      const index = Math.max(0, Math.min(entries.length - 1, step > 0 ? Math.round(relativeX / step) : 0));
      const [date, value] = entries[index];
      const [x, y] = points[index];

      const centerX = (x / width) * rect.width;
      const centerY = (y / SPARKLINE_HEIGHT) * rect.height;

      marker.style.left = `${centerX}px`;
      marker.style.top = `${centerY}px`;
      marker.hidden = false;

      tooltip.textContent = `${date}: ${formatValueForUnit(value, this.element.dataset.unit)}`;
      tooltip.hidden = false;

      const halfTooltipWidth = tooltip.offsetWidth / 2;
      const clampedX = Math.min(Math.max(centerX, halfTooltipWidth), rect.width - halfTooltipWidth);

      tooltip.style.left = `${clampedX}px`;
      tooltip.style.top = `${centerY}px`;
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

    const values = Object.values(this.data);
    const today = values.at(-1) ?? 0;
    const yesterday = values.at(-2) ?? 0;
    const peak = Math.max(...values, 0);
    const average = values.length > 0 ? Math.round(values.reduce((sum, value) => sum + value, 0) / values.length) : 0;
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
      `<div class="pagemetrics-popover-stat"><span class="pagemetrics-popover-stat-label">${label}</span><span class="pagemetrics-popover-stat-value">${value}</span></div>`;

    const stats = document.createElement('div');
    stats.className = 'pagemetrics-popover-stats';
    stats.innerHTML =
      stat('Today', formatValueForUnit(today, unit)) +
      stat('Yesterday', formatValueForUnit(yesterday, unit)) +
      stat('Peak', formatValueForUnit(peak, unit)) +
      // For a rate tile this is the simple mean of the daily rates, which
      // can differ from the tile's own headline value (a weighted average:
      // sum of numerator / sum of denominator over the period) on a page
      // with uneven day-to-day traffic - that divergence is itself useful
      // information, not redundant with the headline number.
      stat('Average', formatValueForUnit(average, unit)) +
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
      loadTiles(container, link.dataset.period);
    });
  });
}

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

async function loadTiles(container, period) {
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
  initializeTiles(container);
  bindPeriodLinks(container);
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
  loadTiles(container, container.dataset.period || '30');
});
