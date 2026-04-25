const CUP_SVG = `<svg width="72" height="72" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
  <path class="bmc-steam bmc-steam--1" d="M38 38 C40 30 36 22 38 14" stroke="#C9BFB2" stroke-width="2.5" stroke-linecap="round"/>
  <path class="bmc-steam bmc-steam--2" d="M50 36 C52 28 48 20 50 12" stroke="#C9BFB2" stroke-width="2.5" stroke-linecap="round"/>
  <path class="bmc-steam bmc-steam--3" d="M62 38 C64 30 60 22 62 14" stroke="#C9BFB2" stroke-width="2.5" stroke-linecap="round"/>
  <ellipse cx="50" cy="90" rx="36" ry="5.5" fill="#E8E0D8"/>
  <ellipse cx="50" cy="89" rx="30" ry="3.5" fill="#F0E8E0"/>
  <path d="M25 42 L25 78 Q25 87 34 87 L66 87 Q75 87 75 78 L75 42 Z" fill="#FAFAF9" stroke="#D6D3D1" stroke-width="1.5"/>
  <path d="M27 48 L27 78 Q27 85 34 85 L66 85 Q73 85 73 78 L73 48 Z" fill="#5C3D2E"/>
  <path d="M27 48 Q38 44 50 48 Q62 52 73 48" fill="#7B5341"/>
  <path d="M47 63 C47 60 50 58 50 61 C50 58 53 60 53 63 C53 66 50 70 50 70 C50 70 47 66 47 63" fill="#7B5341" opacity="0.6">
    <animate attributeName="opacity" values="0.4;0.8;0.4" dur="2.5s" repeatCount="indefinite"/>
  </path>
  <path d="M75 50 Q91 50 91 64 Q91 78 75 78" stroke="#D6D3D1" stroke-width="2.5" stroke-linecap="round"/>
</svg>`;

const MESSAGES = [
    'Brewing your payment...',
    'Pouring a fresh cup...',
    'Warming things up...',
    'Almost ready...',
    'Steaming ahead...',
];

class BmcCoffeeLoader {
    constructor(wrapper) {
        // wrapper: a DOM element (the .bmc-form-card or similar)
        this.wrapper = wrapper;
        this.el      = null;
        this.timer   = null;
        this.msgIdx  = 0;
    }

    show(message) {
        if (this.el) return;
        this.msgIdx = Math.floor(Math.random() * MESSAGES.length);
        const msg = message || MESSAGES[this.msgIdx];

        this.el = document.createElement('div');
        this.el.className = 'bmc-pub-loader';
        this.el.innerHTML = `
            <div class="bmc-pub-loader__inner">
                <div class="bmc-pub-loader__cup">${CUP_SVG}</div>
                <p class="bmc-pub-loader__msg">${msg}</p>
            </div>`;

        this.wrapper.style.position = 'relative';
        this.wrapper.appendChild(this.el);

        // cycle messages every 2.4 s
        this.timer = setInterval(() => {
            this.msgIdx = (this.msgIdx + 1) % MESSAGES.length;
            const p = this.el && this.el.querySelector('.bmc-pub-loader__msg');
            if (p) p.textContent = MESSAGES[this.msgIdx];
        }, 2400);
    }

    hide() {
        clearInterval(this.timer);
        this.timer = null;
        if (this.el) {
            this.el.remove();
            this.el = null;
        }
    }
}

export default BmcCoffeeLoader;
