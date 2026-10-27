import { defineConfig } from 'vitepress'

const description = 'Typed PHP repositories with consistent translation fallback and batch-loaded relations.'

export default defineConfig({
  title: 'Solo Repository',
  description,
  base: '/Repository/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Repository/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#06b6d4' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Repository' }],
    ['meta', { property: 'og:description', content: description }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/' },
      { text: 'API', link: '/guide/api' },
      { text: 'Details', link: '/guide/details' },
      { text: 'v1.0.0', link: 'https://github.com/SoloPHP/Repository/releases' },
    ],

    sidebar: [
      {
        text: 'Solo Repository · 1.0',
        items: [
          { text: 'Getting started', link: '/guide/' },
          { text: 'API reference', link: '/guide/api' },
          { text: 'Behavior in detail', link: '/guide/details' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Repository' },
    ],

    footer: {
      message: 'MIT licensed.',
      copyright: 'Copyright © 2026 SoloPHP',
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/Repository/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: {
      provider: 'local',
    },
  },
})
