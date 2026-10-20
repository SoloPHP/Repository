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
      { text: 'Migration', link: '/guide/migration' },
      { text: 'Details (RU)', link: '/guide/details' },
    ],

    sidebar: [
      {
        text: 'Solo Repository · 1.0',
        items: [
          { text: 'Getting started', link: '/guide/' },
          { text: 'API reference', link: '/guide/api' },
          { text: 'Migration from Base Repository v3', link: '/guide/migration' },
          { text: 'Detailed guide (Russian)', link: '/guide/details' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Repository' },
    ],

    footer: {
      message: 'MIT licensed. Version 1.0.0 in preparation.',
      copyright: `Copyright © 2025-${new Date().getFullYear()} SoloPHP`,
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
