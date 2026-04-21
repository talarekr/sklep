#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
THEME_SLUG="global-parts-clone"
PLUGIN_SLUG="allegro-woo-importer"

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}/theme/${THEME_SLUG}"

cp -r \
  "${ROOT_DIR}/style.css" \
  "${ROOT_DIR}/functions.php" \
  "${ROOT_DIR}/header.php" \
  "${ROOT_DIR}/footer.php" \
  "${ROOT_DIR}/front-page.php" \
  "${ROOT_DIR}/index.php" \
  "${ROOT_DIR}/page.php" \
  "${ROOT_DIR}/single.php" \
  "${ROOT_DIR}/searchform.php" \
  "${ROOT_DIR}/woocommerce.php" \
  "${ROOT_DIR}/assets" \
  "${ROOT_DIR}/template-parts" \
  "${ROOT_DIR}/woocommerce" \
  "${DIST_DIR}/theme/${THEME_SLUG}/"

(
  cd "${DIST_DIR}/theme"
  zip -rq "${DIST_DIR}/${THEME_SLUG}-theme.zip" "${THEME_SLUG}"
)

(
  cd "${ROOT_DIR}/wp-content/plugins"
  zip -rq "${DIST_DIR}/${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
)

echo "Build completed:"
echo " - ${DIST_DIR}/${THEME_SLUG}-theme.zip"
echo " - ${DIST_DIR}/${PLUGIN_SLUG}.zip"
