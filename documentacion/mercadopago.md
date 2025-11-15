# Documentación – Mercado Pago

Esta guía resume todo lo necesario para operar la integración de Mercado Pago dentro del portal de pagos.

## 1. Configuración básica

1. Copia `app/Config/mercadopago_credentials.php.example` a `app/Config/mercadopago_credentials.php`.
2. Completa los campos:
   - **Public Key**: `APP_USR-8eb7aa72-7434-440a-8496-40314c0ecde6`
   - **Access Token**: `APP_USR-7789638414038630-111010-1c0392114c946c76f738cbfdcc9767e2-2977405996`
   - (Opcional) `statement_descriptor` y `notification_url` para adaptar la glosa del cobro y el endpoint de notificaciones.
3. Configura las URLs de retorno (`return_urls.success|failure|pending`) y el `notification_url` para que apunten a `mercadopago_return.php` y `mercadopago_process.php` respectivamente. Si prefieres variables de entorno, exporta `MERCADOPAGO_PUBLIC_KEY`, `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_BASE_URL`, etc. (ver `app/Config/app.php`).
4. Usa la llave `environment` (`production` | `sandbox`) dentro de `app/Config/mercadopago_credentials.php` para elegir automáticamente cuál set de credenciales (bloque `credentials`) se cargará en el backend sin tener que comentar/descomentar manualmente.

> **Logs y storage:** todos los payloads/respuestas se almacenan en `app/storage/mercadopago/` (el nombre del archivo es el hash del transaction_id) y los eventos se registran en `app/logs/mercadopago*.log`.

## 2. Flujo Checkout Pro

1. **Selección de deudas:** el comprador llega a `debts.php`, marca los servicios y elige *Mercado Pago*.
2. **Generación de preferencia:** `pay_mercadopago.php` valida la solicitud, guarda la transacción en `app/storage/mercadopago/` y crea una preferencia (`/checkout/preferences`) mediante `MercadoPagoPaymentService`.
3. **Redirección a Mercado Pago:** el usuario es enviado al `init_point`/`sandbox_init_point` que entrega la API. La experiencia de pago ocurre 100% en el sitio de Mercado Pago.
4. **Retorno del usuario:** al finalizar, Mercado Pago redirige al comprador a `mercadopago_return.php`, pasando `status`, `payment_id`, `preference_id` y `external_reference`. Allí se muestra un resumen amigable y se consulta (opcional) el estado del pago.
5. **Webhook / notificación:** en paralelo, Mercado Pago invoca `mercadopago_process.php` (el `notification_url`), enviando el `payment_id`. El backend consulta `/v1/payments/{id}`, almacena la respuesta y, si el estado es `approved`, limpia la cache de deudas y ejecuta `MercadoPagoIngresarPagoReporter` para notificar al SOAP `IngresarPago`.
6. **Confirmación al cliente:** cuando el webhook confirma el pago se puede enviar un correo propio o simplemente confiar en el mensaje mostrado al volver al portal.

Este flujo corresponde al **Checkout Pro** oficial. Ya no usamos formularios propios ni SDK JS en el navegador; todo ocurre en los servidores de Mercado Pago.

## 3. Tarjetas de prueba (Chile)

| Marca               | Número                | CVV  | Vencimiento |
|---------------------|-----------------------|------|-------------|
| Mastercard crédito  | `5416 7526 0258 2580` | 123  | 11/30       |
| Visa crédito        | `4168 8188 4444 7115` | 123  | 11/30       |
| American Express    | `3757 781744 61804`   | 1234 | 11/30       |
| Mastercard débito   | `5241 0198 2664 6950` | 123  | 11/30       |
| Visa débito         | `4023 6535 2391 4373` | 123  | 11/30       |

> Puedes generar más tarjetas y usuarios de prueba desde el panel de desarrolladores de Mercado Pago si requieres otros escenarios (rechazos, cuotas específicas, etc.).

## 4. Recursos adicionales

- **Repositorio de ejemplo:** `documentacion/mecado pago repo/card-payment-sample-php` contiene el proyecto oficial de Mercado Pago (cliente + servidor Slim) para pruebas aisladas.
- **Endpoints clave:** `pay_mercadopago.php` (crea la preferencia y redirige), `mercadopago_return.php` (muestra el resultado al volver) y `mercadopago_process.php` (consume el webhook/signature). El cliente HTTP está en `app/Services/MercadoPagoPaymentService.php` y el reporter SOAP en `MercadoPagoIngresarPagoReporter.php`.
- **Notificaciones:** si Mercado Pago envía webhooks adicionales, apunta `notification_url` a `https://pagos2.homenet.cl/mercadopago_process.php` para que el backend procese el evento antes de reconciliarlo con los sistemas internos.

Mantén esta carpeta sincronizada con cualquier cambio de credenciales, tarjetas o flujos para que el equipo tenga un único punto de referencia.

## 5. Registro del último pago verificado (11-nov-2025)

- Transacción `mp-9a7e568e3cd147cd9b59` (`payment_id 133343447692`) creada el **11-11-2025 00:50:33 UTC** por `RUT 16.815.441-0` (correo `ktonccc@gmail.com`) por **CLP 20.000** del contrato `7 / INTERNET FTTH 700Mbps`. Ver `app/storage/mercadopago/95ad71e6f1860baa855d58963f8b9ff6878e4153d7c861847d04796d993e2318.json`.
- La API devolvió `status=approved` y `status_detail=accredited` con `payment_method_id=visa` / `payment_type_id=credit_card`; el webhook fue recibido dos veces y quedó persistido en la misma captura JSON anterior.
- `MercadoPagoIngresarPagoReporter` envió el SOAP `IngresarPago` al WSDL `http://ws.homenet.cl/Test_HN_2025.php?wsdl` con glosa `Recaudador=MercadoPago` y obtuvo `return=1` (OK) a las **00:51:17 UTC**.
- `mercadopago-error.log` no registra fallas posteriores a esta operación (última alerta fue un `invalid access token` a las 18:26 UTC). Con esto se confirma que la última recaudación quedó cuadrada tanto en Mercado Pago como en el WS interno.
