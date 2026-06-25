# patologicos.com — escaparate

Sitio estático (un solo `index.html` **self-contained**, 0 dependencias) de **Patológicos**: el escaparate
que vende el proyecto completo — desarrollo con **IA agéntica** (orquestadores + motor de contenido), con
**PROCA** como caso insignia y la oferta *Auditoría de Proceso con IA → producto a la medida*.

```
index.html   ← el escaparate completo
favicon.svg · robots.txt · sitemap.xml   ← ícono + SEO
.github/workflows/deploy.yml   ← auto-deploy a Hostinger por FTP (opción B)
```

## 1) Publicar como repo
Copia esta carpeta a un lugar propio (p. ej. `C:\patologicos.com`), crea un repo vacío en GitHub y:
```bash
cd C:\patologicos.com
git init && git add . && git commit -m "patologicos.com — escaparate inicial"
git branch -M main
git remote add origin https://github.com/<tu-usuario>/patologicos.git
git push -u origin main
```

## 2) Hostinger (elige una)
- **A · Git nativo (recomendada):** hPanel → Avanzado → **GIT** → URL del repo, rama `main`, directorio =
  el `public_html` de patologicos.com → Create. Agrega el **webhook** en GitHub (Settings → Webhooks,
  `application/json`, push) para auto-deploy.
- **B · Actions por FTP:** agrega 3 secrets (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`) y cada push publica
  solo. El workflow ya viene listo (se salta en verde mientras no haya secrets).

Luego apunta **patologicos.com** a Hostinger + activa SSL.

## Pendiente (no bloquea el deploy)
- **Email de contacto:** el CTA usa `mailto:hola@patologicos.com` — crea ese buzón (hPanel → Emails) o cámbialo.
- **Métricas reales del caso PROCA** + capturas (hoy: "[métricas en preparación]").
- Si patologicos.com ya tiene WordPress vivo: decidir si este escaparate lo **reemplaza** o vive en una ruta.
