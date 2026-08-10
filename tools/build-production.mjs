import { cp, mkdir, readFile, readdir, rm, stat, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";

const toolsDir = path.dirname(fileURLToPath(import.meta.url));
const projectDir = path.resolve(toolsDir, "..");
const outputDir = path.join(projectDir, ".production-build");

if (path.dirname(outputDir) !== projectDir || path.basename(outputDir) !== ".production-build") {
  throw new Error(`Unsafe output path: ${outputDir}`);
}

const copyTargets = [
  "index.html",
  "b2b",
  "assets",
  "scripts",
  "styles.css",
  "styles-10107.css",
  "styles-10108.css",
  "styles-10110.css",
  "api",
  ".htaccess",
  "robots.txt",
  "sitemap.xml",
];

await rm(outputDir, { recursive: true, force: true });
await mkdir(outputDir, { recursive: true });

for (const relativePath of copyTargets) {
  await cp(path.join(projectDir, relativePath), path.join(outputDir, relativePath), {
    recursive: true,
  });
}

await mkdir(path.join(outputDir, "data"), { recursive: true });
await cp(
  path.join(projectDir, "data", "repair-stats.json"),
  path.join(outputDir, "data", "repair-stats.json")
);

async function listFiles(directory, prefix = "") {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const relativePath = path.join(prefix, entry.name);
    const absolutePath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...(await listFiles(absolutePath, relativePath)));
    } else if (entry.isFile()) {
      files.push(relativePath);
    }
  }
  return files;
}

const files = await listFiles(outputDir);
const forbiddenPaths = files.filter((file) =>
  /(^|[\\/])(remont|tools|\.git)([\\/]|$)|services\.csv$|site-config\.json$/i.test(file)
);
if (forbiddenPaths.length) {
  throw new Error(`Forbidden production files: ${forbiddenPaths.join(", ")}`);
}

for (const file of files) {
  if (!/\.(?:html|js|css|php|xml|txt|json|htaccess)$/i.test(file) && path.basename(file) !== ".htaccess") {
    continue;
  }
  const contents = await readFile(path.join(outputDir, file), "utf8");
  if (contents.includes("formsubmit.co")) {
    throw new Error(`FormSubmit reference remains in ${file}`);
  }
  if (/\.html$/i.test(file) && /href=["'][^"']*\/remont\//i.test(contents)) {
    throw new Error(`Public price link remains in ${file}`);
  }
}

const manifest = [];
for (const file of files.sort()) {
  const absolutePath = path.join(outputDir, file);
  const data = await readFile(absolutePath);
  const metadata = await stat(absolutePath);
  manifest.push({
    path: file.replaceAll("\\", "/"),
    bytes: metadata.size,
    sha256: createHash("sha256").update(data).digest("hex"),
  });
}

await writeFile(
  path.join(outputDir, "deployment-manifest.json"),
  `${JSON.stringify({ generatedAt: new Date().toISOString(), files: manifest }, null, 2)}\n`,
  "utf8"
);

console.log(`Production build created: ${outputDir}`);
console.log(`Files: ${manifest.length}`);
