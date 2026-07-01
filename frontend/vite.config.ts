import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import fs from 'fs'
import path from 'path'

const docsSrc = path.resolve(__dirname, '../build/docs-html')
const docsUrlPrefix = '/docs'

/** Serve bundled HTML docs in dev and copy them into dist/docs on production build. */
function quenyxDocsPlugin(): Plugin {
  const copyDocsToDist = () => {
    const outDir = path.resolve(__dirname, 'dist/docs')
    if (!fs.existsSync(docsSrc)) {
      console.warn('[quenyx-docs] build/docs-html not found — run scripts/docs/build-pdfs.ps1 or skip docs copy')
      return
    }
    fs.mkdirSync(outDir, { recursive: true })
    for (const name of fs.readdirSync(docsSrc)) {
      if (!name.endsWith('.html')) continue
      fs.copyFileSync(path.join(docsSrc, name), path.join(outDir, name))
    }
  }

  return {
    name: 'quenyx-docs',
    configureServer(server) {
      server.middlewares.use(docsUrlPrefix, (req, res, next) => {
        if (!fs.existsSync(docsSrc)) {
          next()
          return
        }
        const raw = (req.url ?? '/').split('?')[0] ?? '/'
        const rel = decodeURIComponent(raw.replace(/^\//, ''))
        if (!rel || rel.includes('..')) {
          next()
          return
        }
        const filePath = path.join(docsSrc, rel)
        if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
          next()
          return
        }
        res.setHeader('Content-Type', 'text/html; charset=utf-8')
        res.end(fs.readFileSync(filePath))
      })
    },
    closeBundle: copyDocsToDist,
  }
}

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react(), quenyxDocsPlugin()],
})
