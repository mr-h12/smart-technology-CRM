# Font files (Design System §4.1)

Three families, self-hosted, ten faces. `§1` puts this system on company
premises and `D-59` limits outside reach to a Cloudflare tunnel for five named
users, so a typeface fetched from a public CDN at page load is a dependency the
product is not allowed to have. The bytes ship with the repository.

## What is here and why these weights

`§4.1` assigns 400 body, 500 labels, 600 headings/actions, 700 key totals only,
and gives monospace to codes, IDs and audit metadata. Monospace never carries a
heading, so it ships 400 and 500 and no more.

| Family | Subset | Weights | Role (§4.1) |
|---|---|---|---|
| Inter | `latin` | 400 · 500 · 600 · 700 | English and numbers |
| Noto Sans Arabic | `arabic` | 400 · 500 · 600 · 700 | Arabic |
| Noto Sans Mono | `latin` | 400 · 500 | Codes, IDs, audit metadata |

Ten faces, 324 kB on disk (Vite reports the same, decimal kB). No italics: `§4.1` names none, and Arabic has no
italic tradition — a browser asked for one synthesises a slant, which is worse
than not offering it.

## The subsets are the mechanism, not a size optimisation

`D-70` fixes Western numerals `0-9` in every locale, delivered by Inter. That
rests on a fact about these exact files, measured with `fontTools` rather than
assumed:

| File | `0-9` | `٠-٩` | Arabic letters |
|---|---|---|---|
| `inter-latin-400` | **all** | none | none |
| `noto-sans-arabic-arabic-400` | **none** | all | all |
| `noto-sans-mono-latin-400` | all | none | none |

The Arabic subset carries no Western digits at all, so `0-9` can only be drawn
by Inter. The stack order in `../css/app.css` states the documented intent; this
disjointness is what makes it hold even if someone reorders it by accident.

**The trap this avoids:** the same package also publishes a `latin` subset of
Noto Sans Arabic which *does* carry `0-9`. Adding it would silently make stack
order the only thing standing between the product and two different digit
shapes on one screen. Do not add it.

## Provenance

Every file was taken unmodified from an npm package, at a pinned version:

| Package | Version | Path inside the package |
|---|---|---|
| `@fontsource/inter` | 5.3.0 | `files/inter-latin-<weight>-normal.woff2` |
| `@fontsource/noto-sans-arabic` | 5.3.0 | `files/noto-sans-arabic-arabic-<weight>-normal.woff2` |
| `@fontsource/noto-sans-mono` | 5.3.0 | `files/noto-sans-mono-latin-<weight>-normal.woff2` |

None of the three is a project dependency. They were fetched with `npm pack`,
which downloads a tarball without touching `package.json` or `package-lock.json`,
and the files were copied out. Nothing runs `npm ci` to obtain a typeface, so CI
and the on-premise build stay independent of the registry for fonts.

To reproduce, from any directory:

```
npm pack @fontsource/inter@5.3.0
npm pack @fontsource/noto-sans-arabic@5.3.0
npm pack @fontsource/noto-sans-mono@5.3.0
```

Extract each tarball and copy the files named above. Verify against the
checksums below; a mismatch means the bytes are not the ones reviewed here.

```
8909904ab6c872eb994093482a88a28eca2cd95912d7b6fecd72103b0dc07edc  inter-latin-400-normal.woff2
f3779f1efccc4bdcdf9c0a02ab95bf6bd092ed09c48c08cedc725889edd1d19f  inter-latin-500-normal.woff2
f9a06e79cd3a2a20951c0f0e28f66dd0e6d3fda73911d640a2125c8fcb78f21a  inter-latin-600-normal.woff2
6f56409fd3d64bb85f7d070bce20749db2d66b6d63cec586cc22d1c761be2491  inter-latin-700-normal.woff2
4e2ca0745c908761dc5c5db951662873887c59366fa1a5693ad22c0864abf1bd  noto-sans-arabic-arabic-400-normal.woff2
38599e3046a0ceeae9d10fb9c282424d16b7a05f0838478fabe27908fc922722  noto-sans-arabic-arabic-500-normal.woff2
52a2cd4b0ff37ac84ef9af585af2eca0a300d49ea4934cf023827de54fdc354f  noto-sans-arabic-arabic-600-normal.woff2
d2bf5ac762d07f769e4e644a1f919649317cb5b62e20ab9ae02918862627b723  noto-sans-arabic-arabic-700-normal.woff2
1e4b885e90f8e794d33fff5095497e4ce847d8c5fa2b7810d1b10a770d0f8e34  noto-sans-mono-latin-400-normal.woff2
e57dcd563a0fe70859e8e7a75431de1d8199bf6ebbd63cfc1a0e60edf1367498  noto-sans-mono-latin-500-normal.woff2
```

## Licence

All three are SIL Open Font License 1.1, which permits redistribution with the
software. The licence texts are kept beside the files: `LICENSE-Inter.txt`,
`LICENSE-NotoSansArabic.txt`, `LICENSE-NotoSansMono.txt`. OFL requires that the
fonts not be sold on their own and that the licence travel with them — both are
satisfied by keeping these files here.

## Not the same fonts the PDF uses

`docker/php/Dockerfile` installs `fonts-inter`, `fonts-noto-core` and
`fonts-noto-mono` as **operating-system** fonts, for the headless Chrome that
renders PDFs (`P-01`). Those are `.otf`/`.ttf` on the server; these are `.woff2`
served to a browser. They are two different delivery paths for the same three
families and neither substitutes for the other.
