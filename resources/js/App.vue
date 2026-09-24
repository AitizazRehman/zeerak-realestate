<template>
    <v-app>
        <router-view />

        <v-overlay :value="globalLoading" opacity="0.18" z-index="9998">
            <v-card class="global-loader pa-5 text-center" elevation="8">
                <div class="loader-mark mx-auto mb-3">
                    <img
                        :src="appLogoUrl || '/images/zeerak-logo.jpeg'"
                        class="loader-logo"
                        alt=""
                    >
                </div>
                <v-progress-circular
                    indeterminate
                    color="#165134"
                    size="34"
                    width="3"
                />
                <div class="mt-3 font-weight-medium">Please wait…</div>
                <div class="caption grey--text">Processing your request</div>
            </v-card>
        </v-overlay>

        <v-snackbar
            v-model="snackbar.show"
            :color="snackbarColor"
            :timeout="snackbar.timeout"
            top
            right
            multi-line
            elevation="12"
            class="global-snackbar"
        >
            <div class="d-flex align-center">
                <v-avatar size="34" class="message-icon mr-3">
                    <v-icon small color="white">{{ snackbarIcon }}</v-icon>
                </v-avatar>
                <div>
                    <div class="font-weight-bold">{{ snackbarTitle }}</div>
                    <div class="message-text">{{ snackbar.text }}</div>
                </div>
                <v-spacer />
                <v-btn icon small dark @click="snackbar.show=false">
                    <v-icon small>mdi-close</v-icon>
                </v-btn>
            </div>
        </v-snackbar>
    </v-app>
</template>

<script>
import uiBus from './services/uiBus'

export default {
    name: 'App',

    data() {
        return {
            activeRequests: 0,
            globalLoading: false,
            loaderTimer: null,
            lastMessage: '',
            lastMessageAt: 0,
            lastApiMessageAt: 0,
            lastApiMessageType: '',
            snackbar: {
                show: false,
                type: 'success',
                text: '',
                timeout: 4200
            }
        }
    },

    computed: {
        snackbarColor() {
            return this.snackbar.type === 'error'
                ? 'error'
                : this.snackbar.type === 'warning'
                    ? 'warning'
                    : this.snackbar.type === 'info'
                        ? 'info'
                        : '#165134'
        },

        snackbarIcon() {
            return this.snackbar.type === 'error'
                ? 'mdi-alert-circle-outline'
                : this.snackbar.type === 'warning'
                    ? 'mdi-alert-outline'
                    : this.snackbar.type === 'info'
                        ? 'mdi-information-outline'
                        : 'mdi-check-circle-outline'
        },

        snackbarTitle() {
            return this.snackbar.type === 'error'
                ? 'Request failed'
                : this.snackbar.type === 'warning'
                    ? 'Attention'
                    : this.snackbar.type === 'info'
                        ? 'Information'
                        : 'Success'
        },

        appCompanyName() {
            return this.$store.getters['settings/companyName']
        },

        appLogoUrl() {
            return this.$store.getters['settings/logoUrl']
        }
    },

    created() {
        this.preloadLoaderLogo(this.appLogoUrl || '/images/zeerak-logo.jpeg')
    },

    watch: {
        appCompanyName() {
            this.applyBranding()
        },

        appLogoUrl(value) {
            this.preloadLoaderLogo(value || '/images/zeerak-logo.jpeg')
            this.applyBranding()
        }
    },

    mounted() {
        uiBus.$on('api:start', this.startLoading)
        uiBus.$on('api:finish', this.finishLoading)
        uiBus.$on('message', this.showMessage)

        this.$root.$on('show-success', this.onSuccessMessage)
        this.$root.$on('show-error', this.onErrorMessage)
        this.$root.$on('show-warning', this.onWarningMessage)
        this.$root.$on('show-info', this.onInfoMessage)

        this.applyBranding()
        this.$store.dispatch('settings/loadCompany')
    },

    beforeDestroy() {
        uiBus.$off('api:start', this.startLoading)
        uiBus.$off('api:finish', this.finishLoading)
        uiBus.$off('message', this.showMessage)
        this.$root.$off('show-success', this.onSuccessMessage)
        this.$root.$off('show-error', this.onErrorMessage)
        this.$root.$off('show-warning', this.onWarningMessage)
        this.$root.$off('show-info', this.onInfoMessage)

        if (this.loaderTimer) {
            clearTimeout(this.loaderTimer)
        }
    },

    methods: {
        preloadLoaderLogo(url) {
            if (!url) return

            const existing = document.querySelector('link[data-zeerak-loader-preload]')
            if (existing && existing.getAttribute('href') === url) return

            if (existing) existing.parentNode.removeChild(existing)

            const preload = document.createElement('link')
            preload.rel = 'preload'
            preload.as = 'image'
            preload.href = url
            preload.setAttribute('data-zeerak-loader-preload', '1')
            document.head.appendChild(preload)

            const image = new Image()
            image.decoding = 'async'
            image.src = url
        },

        applyBranding() {
            const company = this.appCompanyName || 'ZeeraK Real Estate & Builders'
            const logo = this.appLogoUrl || '/images/zeerak-logo.jpeg'

            document.title = company + ' | Management Portal'
            this.applyCircularFavicon(logo)
        },

        applyCircularFavicon(logo) {
            const icons = document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"], link[rel="apple-touch-icon"]')

            const applyIcon = function (href, type) {
                icons.forEach(function (icon) {
                    icon.setAttribute('href', href)

                    if (type && icon.getAttribute('rel') !== 'apple-touch-icon') {
                        icon.setAttribute('type', type)
                    }
                })
            }

            // Keep the original logo as an immediate fallback while the round favicon is prepared.
            applyIcon(logo)

            const image = new Image()

            image.onload = function () {
                try {
                    const size = 128
                    const borderWidth = 7
                    const outerRadius = (size / 2) - 4
                    const innerRadius = outerRadius - borderWidth
                    const canvas = document.createElement('canvas')
                    const context = canvas.getContext('2d')

                    if (!context) return

                    canvas.width = size
                    canvas.height = size
                    context.clearRect(0, 0, size, size)

                    // Circular white base with ZeeraK green border.
                    context.beginPath()
                    context.arc(size / 2, size / 2, outerRadius, 0, Math.PI * 2)
                    context.fillStyle = '#ffffff'
                    context.fill()
                    context.lineWidth = borderWidth
                    context.strokeStyle = '#165134'
                    context.stroke()

                    // Clip the actual company logo inside the circle.
                    context.save()
                    context.beginPath()
                    context.arc(size / 2, size / 2, innerRadius - 3, 0, Math.PI * 2)
                    context.clip()

                    const maxSize = (innerRadius - 8) * 2
                    const scale = Math.min(maxSize / image.naturalWidth, maxSize / image.naturalHeight)
                    const width = image.naturalWidth * scale
                    const height = image.naturalHeight * scale
                    const x = (size - width) / 2
                    const y = (size - height) / 2

                    context.drawImage(image, x, y, width, height)
                    context.restore()

                    applyIcon(canvas.toDataURL('image/png'), 'image/png')
                } catch (e) {
                    applyIcon(logo)
                }
            }

            image.onerror = function () {
                applyIcon(logo)
            }

            image.src = logo
        },

        onSuccessMessage(message) {
            this.showMessage({ type: 'success', text: message })
        },

        onErrorMessage(message) {
            this.showMessage({ type: 'error', text: message })
        },

        onWarningMessage(message) {
            this.showMessage({ type: 'warning', text: message })
        },

        onInfoMessage(message) {
            this.showMessage({ type: 'info', text: message })
        },

        startLoading() {
            this.activeRequests += 1

            if (this.activeRequests === 1) {
                if (this.loaderTimer) clearTimeout(this.loaderTimer)

                this.loaderTimer = setTimeout(() => {
                    if (this.activeRequests > 0) {
                        this.globalLoading = true
                    }
                }, 140)
            }
        },

        finishLoading() {
            this.activeRequests = Math.max(0, this.activeRequests - 1)

            if (this.activeRequests === 0) {
                if (this.loaderTimer) {
                    clearTimeout(this.loaderTimer)
                    this.loaderTimer = null
                }

                this.globalLoading = false
            }
        },

        showMessage(payload) {
            const data = typeof payload === 'string'
                ? { type: 'success', text: payload }
                : (payload || {})

            const text = String(data.text || '').trim()
            if (!text) return

            const now = Date.now()
            const type = data.type || 'success'

            if (!data.source && type === this.lastApiMessageType && now - this.lastApiMessageAt < 900) {
                return
            }

            if (text === this.lastMessage && now - this.lastMessageAt < 1200) {
                return
            }

            this.lastMessage = text
            this.lastMessageAt = now

            if (data.source === 'api') {
                this.lastApiMessageAt = now
                this.lastApiMessageType = type
            }

            this.snackbar = {
                show: true,
                type: type,
                text: text,
                timeout: data.timeout || (data.type === 'error' ? 6000 : 4200)
            }
        }
    }
}
</script>

<style scoped>
.global-loader{
    min-width:190px;
    border-radius:18px!important;
    border:1px solid rgba(22,81,52,.1)
}
.loader-mark{
    width:62px;
    height:62px;
    border-radius:50%;
    background:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 6px 20px rgba(22,81,52,.10);
    border:2px solid rgba(22,81,52,.18);
    overflow:hidden
}
.loader-logo{
    width:100%;
    height:100%;
    display:block;
    object-fit:contain;
    border-radius:50%;
    padding:4px;
    background:#fff
}
.global-snackbar ::v-deep .v-snack__wrapper{
    border-radius:14px!important;
    min-width:340px;
    max-width:520px
}
.message-icon{
    background:rgba(255,255,255,.18)!important
}
.message-text{
    line-height:1.35
}
</style>
