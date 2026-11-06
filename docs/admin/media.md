# MurCMS admin: file and image manager

Date: 2026-09-07
Status: current MurCMS 0.4.2 behavior

## Route

```text
/admin/media
/admin/media/delete
/admin/media/download
/admin/media/bulk/download
```

Access: `editor` role and higher (`content` is accepted only as a legacy alias).

Files are stored under:

```text
root/uploads/
```

Public URLs are served as:

```text
/uploads/{path}
```

The local PHP dev server also serves `/uploads` as static files. On normal hosting, the web server serves this directory directly.

## Features

The current file manager can:

- navigate through subdirectories;
- create subdirectories with safe ASCII names;
- switch between gallery view and list view;
- upload multiple files;
- enable or disable WebP conversion per upload; conversion is enabled by
  default;
- accept drag-and-drop into the upload zone without auto-upload;
- require the user to press the upload button before sending files;
- list images and simple document files;
- open files by public URL;
- download files through an admin route;
- delete files with typed confirmation;
- show URL fields for every file;
- show ready `<img>` code for images, including `width`, `height`, `loading="lazy"` and `decoding="async"` when dimensions are available.
- select files in gallery and list views;
- show all files by default or paginate files by 50 or 10 while always keeping
  subdirectories visible;
- select all currently displayed files or invert the current selection;
- open a modal layer with a newline-separated list of selected public URLs and
  a copy button; the POST result remains as a no-JavaScript fallback;
- download selected files as one ZIP;
- move selected files to another folder inside `uploads`;
- delete selected files after typed confirmation.

## Allowed uploads

Images:

```text
jpg
jpeg
png
webp
gif
```

Other currently allowed file types:

```text
csv
txt
pdf
```

SVG upload is intentionally not enabled in this first pass because SVG can contain active content and needs a sanitizer policy before allowing user uploads.

## WebP conversion

The upload form contains a checked-by-default option:

```text
Конвертировать JPEG, PNG и GIF в WebP
```

When it is unchecked, JPEG, PNG, GIF, and WebP files keep their original
format. PDF, CSV, and TXT uploads are always stored without image conversion.
The quality input is disabled in the browser while conversion is unchecked.

Default WebP quality in the admin UI is:

```text
80
```

Reasoning:

- Lighthouse/PageSpeed recommends modern formats such as AVIF/WebP because they usually reduce transferred bytes compared with JPEG/PNG.
- Google WebP documentation shows `cwebp -q 80` as the basic conversion example.
- Google WebP documentation says `cwebp -q` accepts `0..100`, default is `75`, and higher values trade larger files for quality.
- Google WebP FAQ warns that converting a JPEG source to a higher target quality, for example source quality 80 to WebP quality 95, can increase file size.

For ordinary content images, keep `80`. For hero/portfolio images, test `82-86` manually if visible quality loss appears. Avoid defaulting to `95+` because PageSpeed usually benefits more from smaller files and correct dimensions than from near-lossless uploads.

## Server requirements

ZIP download operations and page-delete backup archives require:

```text
PHP zip / ZipArchive
```

`ZipArchive` is part of the supported full admin baseline: without it the corresponding ZIP actions return an error rather than being silently feature-gated.

The current implementation converts uploaded JPEG/PNG/GIF images to WebP when one of these PHP capabilities exists:

```text
GD with imagewebp()
Imagick
```

If neither converter exists, the admin page shows a warning. Already prepared `.webp` files can still be uploaded without recompression.


## Safety notes

- File paths are constrained to `root/uploads`.
- Bulk selection accepts files only after resolving them inside `root/uploads`.
- Bulk moves accept existing destination directories inside `root/uploads`;
  name collisions receive a numeric suffix instead of overwriting a file.
- Dotfiles are hidden from listings.
- Uploaded filenames and directory names are normalized to ASCII-safe names.
- PHP/script extensions are not accepted by the uploader.
- Images are accepted only when their detected JPEG/PNG/WebP/GIF type matches
  the allowlisted filename extension. Uploaded documents still remain untrusted.
- Delete actions only delete selected files, not directories or recursive
  directory trees.
- Uploaded files are not trusted content; keep executable handling disabled for `uploads` on production hosting.
