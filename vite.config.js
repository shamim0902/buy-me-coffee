import {defineConfig} from 'vite'
import {viteStaticCopy} from 'vite-plugin-static-copy'
import vue from '@vitejs/plugin-vue'
import react from '@vitejs/plugin-react'
import liveReload from 'vite-plugin-live-reload';
import path from "path";
import AutoImport from 'unplugin-auto-import/vite'

const {ElementPlusResolver} = require("unplugin-vue-components/resolvers");
const Components = require("unplugin-vue-components/vite");
// https://vitejs.dev/config/

//Add All css and js here
//Important: Key must be output filepath without extension, and value will be the file source
const inputs = {
    'js/boot': 'src/js/boot.js',
    'js/plugin-main-js-file' : 'src/js/main.js',
    'js/BmcPublic' : 'src/js/BmcPublic.js',
    'js/deactivate-feedback' : 'src/js/deactivate-feedback.js',
    'js/customizer.js': 'src/js/customizer.js',
    'js/BmcFormHandler' : 'src/js/BmcFormHandler.js',
    'js/PaymentMethods/paypal-checkout' : 'src/js/PaymentMethods/paypal-checkout.js',
    'js/PaymentMethods/stripe-checkout' : 'src/js/PaymentMethods/stripe-checkout.js',
    //styles
    'css/element' : 'src/scss/admin/app.scss',
    'css/deactivate-feedback' : 'src/scss/admin/deactivate-feedback.scss',
    'css/customizer' : 'src/scss/admin/customizer.scss',
    'css/public-style' : 'src/scss/public/public-style.scss',
    'css/BasicTemplate' : 'src/scss/public/BasicTemplate.scss',

    //Block Editor assets
    'js/Editor/gutenBlock' : 'src/js/Editor/gutenBlock.jsx'
}
export default defineConfig({
    base: './',
    plugins:
        [
            vue(),
            react(),
            // liveReload([
            //     `${__dirname}/**/*\.php`,
            // ]),
            viteStaticCopy({
                targets: [
                    {src: 'src/images/*', dest: 'images', rename: { stripBase: 2 }},
                ]
            }),
            AutoImport({
                resolvers: [ElementPlusResolver()],
            }),
            Components({
                resolvers: [ElementPlusResolver()],
                directives: false
            }),
        ],

    build: {
        manifest: 'manifest.json',
        outDir: 'assets',
        //assetsDir: '',
        publicDir: 'assets',
        //root: '/',
        emptyOutDir: true, // delete the contents of the output directory before each build

        // https://rollupjs.org/guide/en/#big-list-of-options
        rollupOptions: {
            input: inputs,
            output: {
                // Use hashed JS filenames to avoid stale module caches across upgrades.
                // The PHP Vite manifest loader already resolves the actual file names.
                chunkFileNames: '[name]-[hash].js',
                entryFileNames: '[name]-[hash].js',


            },
        },
    },

    resolve: {
        alias: {
            'vue': 'vue/dist/vue.esm-bundler.js',
            '@': path.resolve(__dirname, 'resources/admin'),
        },
    },

    server: {
        port: 3000,
        strictPort: true,
        hmr: {
            port: 3000,
            host: 'localhost',
            protocol: 'ws',
        },
        cors: {
            origin: "*",
            methods: ["GET"],
            allowedHeaders: ["Content-Type", "Authorization"],
        },
    }
})
