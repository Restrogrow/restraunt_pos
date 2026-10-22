// Turns assets/blog/src/*.svg into assets/blog/<slug>.webp (1200x630) with the image's description,
// artist and copyright written into the file (EXIF), for Google Images.
//
//   python scripts/blog-covers/make_covers.py
//   npm i --no-save sharp            (once; sharp is not a project dependency)
//   node scripts/blog-covers/build.cjs
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

const root = path.resolve(__dirname, '..', '..');
const src = path.join(root, 'assets', 'blog', 'src');
const out = path.join(root, 'assets', 'blog');
const year = new Date().getFullYear();
const unescape = (s) => s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#x27;/g, "'");

(async () => {
  let n = 0;
  for (const file of fs.readdirSync(src).filter((f) => f.endsWith('.svg'))) {
    const svg = fs.readFileSync(path.join(src, file), 'utf8');
    const title = unescape((svg.match(/<title[^>]*>([^<]*)<\/title>/) || [])[1] || file);
    await sharp(Buffer.from(svg), { density: 96 })
      .resize({ width: 1200, height: 630 })
      .webp({ quality: 86 })
      .withExif({ IFD0: { ImageDescription: title, Artist: 'RestroGrow', Copyright: `(c) ${year} RestroGrow, restrogrow.com` } })
      .toFile(path.join(out, file.replace(/\.svg$/, '.webp')));
    n++;
  }
  console.log(`Built ${n} covers in ${out}`);
})();
