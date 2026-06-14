import { defineConfig } from 'astro/config';
export default defineConfig({
  site: 'https://www.dev.adherents.lishan.fr',
  output: 'static',
  build: { assets: 'assets' },
  compressHTML: true,
});
