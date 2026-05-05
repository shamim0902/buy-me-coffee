<template>
    <Teleport to="body">
        <div v-if="visible" class="bmc-guided-tour" role="dialog" aria-modal="true" aria-live="polite">
            <div class="bmc-guided-tour__scrim"></div>
            <div class="bmc-guided-tour__spotlight" :style="spotlightStyle"></div>
            <section class="bmc-guided-tour__panel" :style="panelStyle">
                <div class="bmc-guided-tour__meta">
                    <span>Step {{ currentIndex + 1 }} of {{ steps.length }}</span>
                    <button type="button" class="bmc-guided-tour__close" @click="finish">Skip</button>
                </div>
                <h2>{{ currentStep.title }}</h2>
                <p>{{ currentStep.description }}</p>
                <div class="bmc-guided-tour__actions">
                    <button
                        type="button"
                        class="bmc-guided-tour__btn bmc-guided-tour__btn--secondary"
                        :disabled="currentIndex === 0"
                        @click="prev"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="bmc-guided-tour__btn bmc-guided-tour__btn--primary"
                        @click="isLastStep ? finish() : next()"
                    >
                        {{ isLastStep ? 'Finish' : 'Next' }}
                    </button>
                </div>
            </section>
        </div>
    </Teleport>
</template>

<script>
export default {
    name: 'GuidedTour',
    data() {
        return {
            visible: false,
            currentIndex: 0,
            targetRect: null,
            panel: {
                top: 120,
                left: 24,
            },
            positionFrame: null,
            positionTimer: null,
            listenersBound: false,
            steps: [
                {
                    target: '[data-bmc-tour="dashboard-header"]',
                    title: 'Start from your dashboard',
                    description: 'This is your command center for revenue, supporters, subscriptions, and recent activity.',
                },
                {
                    target: '[data-bmc-tour="metrics"]',
                    title: 'Track your progress',
                    description: 'These cards summarize revenue, supporters, active subscriptions, and recurring income at a glance.',
                },
                {
                    target: '[data-bmc-tour="settings"]',
                    title: 'Customize the donation form',
                    description: 'Use Settings to control form fields, currency, design, shortcodes, and account page behavior.',
                },
                {
                    target: '[data-bmc-tour="gateways"]',
                    title: 'Connect payments',
                    description: 'Set up Stripe or PayPal here so supporters can send one-time or recurring payments.',
                },
                {
                    target: '[data-bmc-tour="preview"]',
                    title: 'Preview the supporter experience',
                    description: 'Open the public donation page any time to review what your supporters will see.',
                },
                {
                    target: '[data-bmc-tour="quick-setup"]',
                    title: 'Use Quick Setup when needed',
                    description: 'Quick Setup is still available if you want a step-by-step setup form for profile, form, and payment details.',
                },
            ],
        };
    },
    computed: {
        currentStep() {
            return this.steps[this.currentIndex] || this.steps[0];
        },
        isLastStep() {
            return this.currentIndex >= this.steps.length - 1;
        },
        spotlightStyle() {
            if (!this.targetRect) {
                return { display: 'none' };
            }

            return {
                top: `${this.targetRect.top - 6}px`,
                left: `${this.targetRect.left - 6}px`,
                width: `${this.targetRect.width + 12}px`,
                height: `${this.targetRect.height + 12}px`,
            };
        },
        panelStyle() {
            return {
                top: `${this.panel.top}px`,
                left: `${this.panel.left}px`,
            };
        },
    },
    methods: {
        shouldRun() {
            return !!(window.BuyMeCoffeeAdmin?.show_guided_tour || window.BuyMeCoffeeAdmin?.force_guided_tour);
        },
        start() {
            this.visible = true;
            this.currentIndex = 0;

            if (this.$route.name !== 'Dashboard') {
                this.$router.push({ name: 'Dashboard' });
            }

            this.$nextTick(() => {
                setTimeout(() => this.refreshPosition(true), 180);
            });
        },
        next() {
            if (this.currentIndex < this.steps.length - 1) {
                this.currentIndex++;
                this.$nextTick(() => this.refreshPosition(true));
            }
        },
        prev() {
            if (this.currentIndex > 0) {
                this.currentIndex--;
                this.$nextTick(() => this.refreshPosition(true));
            }
        },
        finish() {
            this.visible = false;
            this.clearPositionTimers();
            this.unbindEvents();
            if (this.$completeGuidedTour) {
                this.$completeGuidedTour('done');
                return;
            }

            this.$saveData('buymecoffee_guided_tour', 'done');
        },
        refreshPosition(scrollTarget = false) {
            if (!this.visible) {
                return;
            }

            const target = this.findVisibleTarget(this.currentStep.target);
            if (!target) {
                this.targetRect = null;
                this.panel = {
                    top: Math.max(72, Math.round(window.innerHeight / 2 - 110)),
                    left: Math.max(16, Math.round(window.innerWidth / 2 - 180)),
                };
                return;
            }

            if (scrollTarget) {
                target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
            }

            clearTimeout(this.positionTimer);
            this.positionTimer = window.setTimeout(() => {
                const rect = target.getBoundingClientRect();
                this.targetRect = rect;
                this.panel = this.getPanelPosition(rect);
            }, scrollTarget ? 180 : 0);
        },
        schedulePositionRefresh() {
            if (!this.visible || this.positionFrame) {
                return;
            }

            this.positionFrame = window.requestAnimationFrame(() => {
                this.positionFrame = null;
                this.refreshPosition(false);
            });
        },
        findVisibleTarget(selector) {
            const targets = Array.from(document.querySelectorAll(selector));
            return targets.find((target) => this.isElementVisible(target));
        },
        isElementVisible(element) {
            const rect = element.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) {
                return false;
            }

            let node = element;
            while (node && node !== document.body) {
                const style = window.getComputedStyle(node);
                if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
                    return false;
                }
                node = node.parentElement;
            }

            return true;
        },
        getPanelPosition(rect) {
            const margin = 16;
            const width = Math.min(360, window.innerWidth - margin * 2);
            let top = rect.bottom + 18;
            let left = rect.left;

            if (top + 220 > window.innerHeight) {
                top = rect.top - 238;
            }

            if (top < margin) {
                top = margin;
            }

            if (left + width > window.innerWidth - margin) {
                left = window.innerWidth - width - margin;
            }

            if (left < margin) {
                left = margin;
            }

            return { top, left };
        },
        onKeydown(event) {
            if (!this.visible) {
                return;
            }

            if (event.key === 'Escape') {
                this.finish();
            } else if (event.key === 'ArrowRight') {
                this.isLastStep ? this.finish() : this.next();
            } else if (event.key === 'ArrowLeft') {
                this.prev();
            }
        },
        bindEvents() {
            if (this.listenersBound) {
                return;
            }

            window.addEventListener('resize', this.schedulePositionRefresh);
            window.addEventListener('scroll', this.schedulePositionRefresh, { capture: true, passive: true });
            window.addEventListener('keydown', this.onKeydown);
            this.listenersBound = true;
        },
        unbindEvents() {
            if (!this.listenersBound) {
                return;
            }

            window.removeEventListener('resize', this.schedulePositionRefresh);
            window.removeEventListener('scroll', this.schedulePositionRefresh, { capture: true });
            window.removeEventListener('keydown', this.onKeydown);
            this.listenersBound = false;
        },
        clearPositionTimers() {
            clearTimeout(this.positionTimer);
            this.positionTimer = null;

            if (this.positionFrame) {
                window.cancelAnimationFrame(this.positionFrame);
                this.positionFrame = null;
            }
        },
    },
    watch: {
        '$route.name'() {
            this.$nextTick(() => {
                setTimeout(() => this.refreshPosition(true), 180);
            });
        },
    },
    mounted() {
        if (!this.shouldRun()) {
            return;
        }

        this.bindEvents();
        this.start();
    },
    beforeUnmount() {
        this.clearPositionTimers();
        this.unbindEvents();
    },
};
</script>

<style scoped>
.bmc-guided-tour {
    position: fixed;
    inset: 0;
    z-index: 100000;
    pointer-events: none;
    font-family: var(--font-sans, Inter, Arial, sans-serif);
}

.bmc-guided-tour__scrim {
    position: absolute;
    inset: 0;
    background: transparent;
    pointer-events: auto;
}

.bmc-guided-tour__spotlight {
    position: absolute;
    z-index: 1;
    border-radius: 10px;
    background: transparent;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.58), 0 0 0 2px #ffffff, 0 14px 36px rgba(15, 23, 42, 0.22);
    transition: all 180ms ease;
}

.bmc-guided-tour__panel {
    position: absolute;
    z-index: 2;
    width: min(360px, calc(100vw - 32px));
    padding: 18px;
    border-radius: 8px;
    background: var(--bg-primary, #fff);
    color: var(--text-primary, #111827);
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.22);
    pointer-events: auto;
    transition: top 180ms ease, left 180ms ease;
}

.bmc-guided-tour__meta,
.bmc-guided-tour__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.bmc-guided-tour__meta {
    margin-bottom: 10px;
    color: var(--text-tertiary, #6b7280);
    font-size: 12px;
    font-weight: 600;
}

.bmc-guided-tour__close {
    border: 0;
    background: transparent;
    color: var(--text-secondary, #4b5563);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.bmc-guided-tour h2 {
    margin: 0 0 6px;
    color: var(--text-primary, #111827);
    font-size: 17px;
    font-weight: 700;
    line-height: 1.3;
}

.bmc-guided-tour p {
    margin: 0 0 16px;
    color: var(--text-secondary, #4b5563);
    font-size: 13px;
    line-height: 1.55;
}

.bmc-guided-tour__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 84px;
    height: 36px;
    padding: 0 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.bmc-guided-tour__btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.bmc-guided-tour__btn--secondary {
    border: 1px solid var(--border-primary, #e5e7eb);
    background: var(--bg-primary, #fff);
    color: var(--text-secondary, #4b5563);
}

.bmc-guided-tour__btn--primary {
    border: 1px solid var(--color-primary, #10b981);
    background: var(--color-primary, #10b981);
    color: #fff;
}
</style>
