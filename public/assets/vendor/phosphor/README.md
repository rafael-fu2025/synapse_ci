# Existing Phosphor icon runtime

The six WOFF/WOFF2 font files are from `@phosphor-icons/web` **2.1.1**, under the MIT license in `LICENSE`. The current `style.css` is the application's combined compatibility stylesheet, not an unmodified individual upstream stylesheet. No runtime or icon design was changed when making these files available to clean checkouts.

The light face is used by the existing sidebar. Regular and duotone faces preserve the combined stylesheet's compatibility. Its historical TTF fallback declarations point to files already absent locally; modern WOFF2/WOFF loading is supported. No TTF distribution is claimed.

Upstream source: <https://registry.npmjs.org/@phosphor-icons/web/-/web-2.1.1.tgz>. The archive was verified against the npm registry integrity value and all six local font files matched the pinned distribution byte-for-byte. License source: `package/LICENSE` in that archive, also <https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/LICENSE>.

SHA-256 values:

| File | SHA-256 |
|---|---|
| `style.css` | `d1df48adc6bdd7e8831e48441fb6c3abe01d501bfa053373dc653cda36b8c97a` |
| `Phosphor-Light.woff2` | `9c45e81688520e967e2dd93d702eed59d25a3a97d8cc82932b1d17267a279887` |
| `Phosphor-Light.woff` | `6d34d73a01305a3260b1e85e89c65bc0170274c5874b8995a01b79c863a7a1c1` |
| `Phosphor.woff2` | `c2ea45ea05ff5c7df1936770c104725f2a68f43fd343f35f3da23a30b27de32a` |
| `Phosphor.woff` | `396c5eae7f2dfb4d1f5432edd87109eda2f92a451b2167e43a26165b82770f75` |
| `Phosphor-Duotone.woff2` | `93d163cd4b1f07736d34bfef80803646ab0322efa947a98e3973a1450e3d20cc` |
| `Phosphor-Duotone.woff` | `fca99cff3f3ec82cec7e5ec9973e229deb8efec7432485b9a339bfa97b6d7b75` |
| `LICENSE` | `81bb1bfd8e3730c19a3895ff4a82839980933504725960c52b1c207b29398f07` |
