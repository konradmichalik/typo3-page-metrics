const SVG_NS = 'http://www.w3.org/2000/svg';

class PageMetricsTile {
  constructor(element) {
    this.element = element;
    this.data = JSON.parse(element.dataset.sparkline || '{}');
    this.popover = null;
    this.renderSparkline();
    element.addEventListener('click', () => this.togglePopover());
    element.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.togglePopover();
      }
    });
  }

  renderSparkline() {
    const values = Object.values(this.data);
    if (values.length === 0) {
      return;
    }

    const max = Math.max(...values, 1);
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${(values.length - 1) * 4} 40`);
    svg.setAttribute('class', 'pagemetrics-sparkline');
    svg.setAttribute('aria-hidden', 'true');

    const points = values.map((value, index) => `${index * 4},${40 - (value / max) * 40}`).join(' ');
    const polyline = document.createElementNS(SVG_NS, 'polyline');
    polyline.setAttribute('points', points);
    polyline.setAttribute('fill', 'none');
    polyline.setAttribute('stroke', 'currentColor');
    polyline.setAttribute('stroke-width', '2');
    svg.appendChild(polyline);
    this.element.appendChild(svg);
  }

  togglePopover() {
    if (this.popover) {
      this.popover.remove();
      this.popover = null;
      this.element.setAttribute('aria-expanded', 'false');
      return;
    }

    const values = Object.values(this.data);
    const today = values.at(-1) ?? 0;
    const yesterday = values.at(-2) ?? 0;
    const peak = Math.max(...values, 0);

    this.popover = document.createElement('div');
    this.popover.className = 'pagemetrics-popover';
    this.popover.innerHTML = `
      <dl>
        <dt>Today</dt><dd>${today}</dd>
        <dt>Yesterday</dt><dd>${yesterday}</dd>
        <dt>Peak</dt><dd>${peak}</dd>
      </dl>
    `;
    this.element.appendChild(this.popover);
    this.element.setAttribute('aria-expanded', 'true');
  }
}

document.querySelectorAll('.pagemetrics-tile').forEach((element) => new PageMetricsTile(element));
