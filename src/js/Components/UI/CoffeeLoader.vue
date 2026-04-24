<template>
    <div class="bmc-loader" :class="{ 'bmc-loader--visible': loading }">
      <div class="bmc-loader__inner">
        <div class="bmc-loader__cup">
          <svg width="90" height="90" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
            <!-- Steam wisps -->
            <path class="bmc-steam bmc-steam--1" d="M38 38 C40 30 36 22 38 14" stroke="#C9BFB2" stroke-width="2.5" stroke-linecap="round" />
            <path class="bmc-steam bmc-steam--2" d="M50 36 C52 28 48 20 50 12" stroke="#C9BFB2" stroke-width="2.5" stroke-linecap="round" />
            <path class="bmc-steam bmc-steam--3" d="M62 38 C64 30 60 22 62 14" stroke="#C9BFB2" stroke-width="2.5" stroke-linecap="round" />

            <!-- Saucer -->
            <ellipse cx="50" cy="90" rx="36" ry="5.5" fill="#E8E0D8" />
            <ellipse cx="50" cy="89" rx="30" ry="3.5" fill="#F0E8E0" />

            <!-- Cup body -->
            <path d="M25 42 L25 78 Q25 87 34 87 L66 87 Q75 87 75 78 L75 42 Z" fill="#FAFAF9" stroke="#D6D3D1" stroke-width="1.5" />

            <!-- Coffee liquid -->
            <path d="M27 48 L27 78 Q27 85 34 85 L66 85 Q73 85 73 78 L73 48 Z" fill="#5C3D2E" />

            <!-- Coffee surface shimmer -->
            <path d="M27 48 Q38 44 50 48 Q62 52 73 48" fill="#7B5341" />

            <!-- Heart latte art -->
            <path d="M47 63 C47 60 50 58 50 61 C50 58 53 60 53 63 C53 66 50 70 50 70 C50 70 47 66 47 63"
              fill="#7B5341" opacity="0.6">
              <animate attributeName="opacity" values="0.4;0.8;0.4" dur="2.5s" repeatCount="indefinite" />
            </path>

            <!-- Handle -->
            <path d="M75 50 Q91 50 91 64 Q91 78 75 78" stroke="#D6D3D1" stroke-width="2.5" stroke-linecap="round" />
          </svg>
        </div>
        <p class="bmc-loader__msg" :key="msgIndex">{{ messages[msgIndex] }}</p>
      </div>
    </div>
</template>

<script>
export default {
  name: 'CoffeeLoader',
  props: {
    loading: { type: Boolean, default: false }
  },
  data() {
    return {
      msgIndex: 0,
      timer: null,
      messages: [
        'Brewing your data...',
        'Pouring a fresh cup...',
        'Warming things up...',
        'Steaming ahead...',
        'Almost ready to serve...',
      ]
    };
  },
  watch: {
    loading(val) {
      if (val) this.start();
      else this.stop();
    }
  },
  methods: {
    start() {
      this.msgIndex = Math.floor(Math.random() * this.messages.length);
      this.timer = setInterval(() => {
        this.msgIndex = (this.msgIndex + 1) % this.messages.length;
      }, 2400);
    },
    stop() {
      clearInterval(this.timer);
      this.timer = null;
    }
  },
  mounted() {
    if (this.loading) this.start();
  },
  beforeUnmount() {
    this.stop();
  }
};
</script>

<style scoped>
.bmc-loader {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, var(--bg-primary) 88%, transparent);
  backdrop-filter: blur(6px);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
}

.bmc-loader--visible {
  opacity: 1;
  pointer-events: auto;
}


.bmc-loader__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.bmc-loader__cup {
  width: 90px;
  height: 90px;
  flex-shrink: 0;
  margin-bottom: 14px;
  animation: cup-bob 2.8s ease-in-out infinite;
}

.bmc-loader__cup svg {
  display: block;
  width: 90px;
  height: 90px;
  overflow: visible;
}

.bmc-loader__msg {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-secondary);
  margin: 0;
  letter-spacing: 0.01em;
  animation: msg-pulse 2.4s ease-in-out infinite;
}

/* Cup gentle bob */
@keyframes cup-bob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}

/* Steam rise + fade */
.bmc-steam { animation: steam-up 2s ease-out infinite; }
.bmc-steam--2 { animation-delay: 0.45s; }
.bmc-steam--3 { animation-delay: 0.9s; }

@keyframes steam-up {
  0% { opacity: 0; transform: translateY(0) scaleY(0.4); }
  25% { opacity: 0.55; }
  100% { opacity: 0; transform: translateY(-12px) scaleY(1.15); }
}

/* Message soft pulse */
@keyframes msg-pulse {
  0%, 100% { opacity: 0.55; }
  50% { opacity: 1; }
}

</style>
