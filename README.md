# Portal de Pagos HomeNet

Este proyecto contiene el portal para que los clientes de HomeNet consulten y paguen sus deudas a través de Webpay.

## Requisitos

- PHP 8.1 o superior (con `soap` habilitado).
- Composer (opcional, si decides manejar dependencias mediante `composer.json`).
- Dependencias locales incluidas en `vendor/` (si optas por ignorar la carpeta en git, recuerda ejecutar `composer install`).

## Puesta en marcha

```bash
php -S 127.0.0.1:8000 -t .
```

Visita `http://127.0.0.1:8000` para acceder al portal.

## Estructura destacada

- `app/` – bootstrap, helpers, servicios y plantillas.
- `assets/` – estilos y scripts para la interfaz.
- `index.php`, `pay.php`, `return.php`, `final.php` – flujo principal de consulta y pago.

## Configuración

Las credenciales y endpoints se centralizan en `app/Config/app.php`. Asegúrate de mantener las llaves y certificados en un lugar seguro.

### Flow

1. Completa `app/Config/flow_credentials.php` con los API Key/Secret entregados por Flow o expone `FLOW_API_KEY` / `FLOW_SECRET_KEY`.
2. Revisa que las URLs `url_confirmation` y `url_return` apunten a tus dominios públicos (`flow_confirm.php` y `flow_return.php` respectivamente).
3. Cuando la transacción quede en estado `status = 2` el sistema notificará automáticamente al servicio `IngresarPago`. Puedes revisar los registros en `app/logs/flow*.log`.

### Zumpago

1. Mantén actualizados `company_code`, `xml_key`, `verification_key` y `payment_methods` dentro de `app/Config/app.php`.
2. Zumpago requiere que se declare el trío de URLs (`response`, `notification`, `cancellation`). Ajusta los dominios si cambian los hostnames del portal.
3. Los XML generados, payloads cifrados y errores quedan registrados en `app/logs/zumpago.log` y `app/storage/zumpago/`.

### Mercado Pago

1. Copia `app/Config/mercadopago_credentials.php.example` como `app/Config/mercadopago_credentials.php`.
2. Completa `public_key` y `access_token` con las credenciales de prueba o producción que te entregó Mercado Pago (ya se agregó la dupla de prueba `APP_USR-8eb7aa72…` / `APP_USR-77896384…`).
3. Define `notification_url` (debe apuntar a `https://pagos2.homenet.cl/mercadopago_process.php`) y las `return_urls` para que el comprador vuelva a `mercadopago_return.php` tras pagar.
4. El flujo usa **Checkout Pro**: `pay_mercadopago.php` genera una preferencia, redirige al `init_point` y el resultado se procesa vía webhook. Revisa `documentacion/mercadopago.md` para más detalles operativos.

#### Tarjetas de prueba (Chile)

| Marca               | Número             | CVV  | Vencimiento |
|---------------------|--------------------|------|-------------|
| Mastercard crédito  | `5416 7526 0258 2580` | `123` | `11/30` |
| Visa crédito        | `4168 8188 4444 7115` | `123` | `11/30` |
| American Express    | `3757 781744 61804`   | `1234`| `11/30` |
| Mastercard débito   | `5241 0198 2664 6950` | `123` | `11/30` |
| Visa débito         | `4023 6535 2391 4373` | `123` | `11/30` |

## GitHub

1. Inicializa el repositorio (ya ejecutado en este entorno): `git init`.
2. Añade los archivos y haz el primer commit:
   ```bash
   git add .
   git commit -m "chore: bootstrap portal de pagos"
   ```
3. Configura el remoto:
   ```bash
   git remote add origin https://github.com/ktonccc/portal.git
   git branch -M main
   git push -u origin main
   ```

> Nota: el repositorio en GitHub es privado; asegúrate de tener permisos y autenticación configurados en tu entorno (SSH o HTTPS con PAT).
