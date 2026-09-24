# AI Product Studio — Image Workflow Enhancement Plan

**Module:** `Meetanshi/AiProductStudio`
**Prepared:** 21 September 2026
**Scope:** Bulk image crop/resize, SKU-folder auto-save, and bulk compression for product images.

---

## Important Clarification: "AI" vs. Image Processing

None of the three steps below require actual AI (image-generation models such as Gemini).

- Cropping to fixed dimensions, saving to a folder, and compressing to a target file size are **pure server-side image processing** using PHP GD.
- The module is *named* "AI Product Studio," but these tasks are standard image operations. The existing `CropResize.php` controller already does GD-based crop/resize with no AI/API calls.
- Using a real AI service for this would be **slower, cost money per image, and give worse results** — AI image models do **not** guarantee exact pixel dimensions or an exact KB file size.

**Conclusion:** The fast, reliable, and cheap path is the non-AI GD approach (already partly built). Doing it "with AI" would not reduce development time; it would add cost and complexity.

**Development time is the same either way: ~6–8.5 days.**

---

## Step 1 — Remove Upload Size Restriction ("Images and Videos")

**Goal:** Allow large original images (e.g. 20MB) to be uploaded to the product "Images and Videos" section.

**Effort: Small (0.5–1 day)** — but touches server config outside the module.

The size limit is **not in this module**. It is enforced across several layers:

- `upload_max_filesize` and `post_max_size` in **php.ini** (server-level; controls the hard stop).
- Magento's gallery uploader max file size (needs a small **plugin** on the image uploader).
- Web server limits — nginx `client_max_body_size` / Apache `LimitRequestBody`.

**Caveat:** Allowing very large originals (20MB+) increases storage use and memory usage during Step 2/3 processing (GD loads the full image into memory).

**Decision needed:** Do you have server-level access to raise the PHP/web-server upload limits?

---

## Step 2 — Crop/Resize to 5 Sizes + Auto-Save to SKU Folder + Delete Original

**Goal:**
1. Crop selected images to the 5 required dimensions.
2. Save results directly to `media/wysiwyg/<SKU>/` (auto-create folder).
3. Keep the original image name.
4. Provide the ready-to-use media URL for each output.
5. Delete the original image from "Images and Videos" afterwards (ready for Step 3).

**Required dimensions:**

| Size | Use |
|------|-----|
| 2560 × 1440 | Banner HD |
| 2560 × 1120 | Wide Banner |
| 800 × 800 | Square |
| 720 × 540 | 4:3 |
| 500 × 330 | Card |

**Effort: Medium (2–3 days).**

**Good news:** The crop engine already exists. `CropResize.php` already crops to exactly these 5 dimensions using GD center-crop. What is new:

- **Save to `media/wysiwyg/<SKU>/`** instead of `ai_studio/cropped/`. SKU comes from `getProduct()->getSku()` (the block already has the product). Auto-create the folder. (~0.5 day)
- **Keep original image name** — e.g. `Sydney-travel.jpg` → `media/wysiwyg/EAUTASTC3P/Sydney-travel.jpg`. Needs a collision rule when 5 sizes share one source name (see Decision below).
- **Return ready-to-use media URL** for each output. Trivial (JS already builds media URLs).
- **Delete the original from the gallery after cropping** — most delicate part. Programmatic gallery removal must update the media gallery tables and reassign the `image/small_image/thumbnail` roles if the deleted image was the main one; otherwise the product can end up with no base image. Needs careful handling + testing. (~1 day)
- **UI wiring** in `studio.phtml` (new buttons/flow). (~0.5 day)

**Example result:** `media/wysiwyg/EAUTASTC3P/Sydney-travel.jpg`
(`EAUTASTC3P` = product SKU; each SKU gets its own folder.)

---

## Step 3 — Bulk Compress All Gallery Images to ≤ 300KB

**Goal:** Bulk-compress all images in "Images and Videos" to 300KB or less, while:

- Keeping the original dimensions.
- Keeping the original proportions / aspect ratio.
- **No cropping.**
- Using JPEG quality optimization.
- **Overwriting** the originals with the compressed versions (same file name, e.g. `Sydney-travel.jpg`).

**Effort: Medium (2–3 days).** Reuses the same GD pipeline — no new technology.

New logic:

- **Compress-to-target loop:** encode → measure bytes → step quality down until ≤ 300KB or a quality floor is reached. (~0.5 day)
- **Keep dimensions/proportions, overwrite originals in place**, same filename; refresh cached resizes. (~1 day)
- **Bulk over all gallery images** with progress. For a single product, AJAX is fine; for "all images" across many products, this should be chunked or a **CLI command** to avoid PHP timeouts. (~0.5 day)
- **Flagging** images that cannot reach 300KB. (~0.5 day)

### Flagging Behaviour (answer to "how do you flag those?")

After compression, the results panel lists every image with its final size and a status icon:

- **Under target** → green with the final size.
- **Over target** → amber warning badge showing the actual size, e.g. **⚠ 340KB**.

Clicking a flagged image opens a **manual compressor** (quality slider + live size preview) so the admin can decide to push further or keep it. This reuses the existing modal/results-grid UI already in the template.

For images that cannot reach 300KB without noticeable quality loss, we compress as close to 300KB as reasonably possible while keeping acceptable visual quality, then flag them. Nothing is silently degraded — the admin stays in control of exceptions.

---

## Important Note — Backup Before Compression

Step 3 **overwrites** the original images, so the process is **not reversible from within the tool**.

**Recommendation:** Take a backup of the media images before running the bulk compression. Options:
- Manual media folder backup, or
- Add a one-click "backup originals to `media/ai_studio/backup/`" before bulk compress (~0.5 day if built in).

---

## PNG / Transparency Note

`imagejpeg` cannot preserve transparency. If any product images are PNGs with alpha:
- Convert to JPEG (transparency flattened onto a background — usually white), or
- Keep as PNG (PNG has no quality scale, so size reduction is limited).

The current code handles `jpg`, `jpeg`, `png` and outputs `.jpg`, so this behaviour should be confirmed.

---

## Effort Summary

| Step | Effort |
|------|--------|
| Step 1 – remove size limit | 0.5–1 day (+ server config access) |
| Step 2 – crop + SKU folder + delete original | 2–3 days |
| Step 3 – bulk compress + flag | 2–3 days |
| Backup + QA/testing | 1.5 days |
| **Total** | **~6–8.5 days** |

*QA covers real product images, PNG-with-transparency handling, and large-file memory usage.*

---

## Decisions Needed Before Build

1. **Filename collision in Step 2:** 5 sizes from one source (e.g. `Sydney-travel.jpg`). Options:
   - Per-size subfolders: `media/wysiwyg/EAUTASTC3P/800x800/Sydney-travel.jpg` (keeps names identical — **recommended**), or
   - Size suffix: `Sydney-travel-800x800.jpg`.
2. **Step 1 scope:** Is server-level access available to raise PHP / web-server upload limits, or should this stay within what Magento allows?
3. **Backup approach for Step 3:** manual backup vs. built-in one-click backup.

---

## Current Module Reference (already exists)

- `Controller/Adminhtml/Studio/CropResize.php` — GD-based center-crop/resize to the 5 dimensions at a chosen JPEG quality; writes to `media/ai_studio/cropped/`. (Reused/extended for Step 2.)
- `Controller/Adminhtml/Studio/Save.php` — saves an image into the product media gallery.
- `Block/Adminhtml/Studio.php` — exposes `getProduct()` (SKU available), `getProductImages()`, `getMediaUrl()`.
- `view/adminhtml/templates/studio.phtml` — admin UI with tabs (Generate / Edit / Crop & Resize), results grid, and preview modal (reused for flagging UI).
