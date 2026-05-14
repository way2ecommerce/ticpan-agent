# W2e_Ticpan — Magento 2 Agent

Módulo agente para [Ticpan](https://ticpan.app) — plataforma de monitorización de salud para tiendas Magento 2.

Recopila métricas de seguridad, rendimiento, SEO, calidad de código e infraestructura, las firma con HMAC-SHA256 y las envía al cloud Ticpan cada hora. El resultado es una puntuación de salud continua visible desde el dashboard de ticpan.app.

---

## Requisitos

| Requisito | Versión mínima |
|-----------|---------------|
| PHP | 8.1 |
| Magento Open Source / Adobe Commerce | 2.4.4 |
| Módulo agente | 1.0.0 |

---

## Instalación

```bash
composer require way2ecommerce/ticpan-agent
bin/magento module:enable W2e_Ticpan
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## Configuración

1. Ve a **Stores → Configuration → W2e Commerce → Ticpan Agent**.
2. Rellena los campos:

| Campo | Descripción |
|-------|-------------|
| **Habilitado** | Activa o desactiva el agente |
| **Endpoint API** | URL del endpoint de ingestión (por defecto `https://ticpan.app/api/v1/report`) |
| **Store ID** | UUID de tu tienda — lo encuentras en ticpan.app tras el onboarding |
| **Secret Key** | Clave secreta HMAC — generada durante el onboarding en ticpan.app |
| **Timeout HTTP** | Segundos máximos de espera por respuesta (por defecto: 30) |

3. Guarda la configuración y limpia caché:

```bash
bin/magento cache:flush
```

---

## Envío manual (sin esperar al cron)

Útil para verificar que la conexión funciona después de configurar el módulo:

```bash
bin/magento ticpan:report:send
```

Comprueba el resultado en `var/log/system.log`:

```
W2e_Ticpan: report sent successfully (HTTP 202).
```

Si hay un error de configuración o de firma aparecerá en el mismo log.

---

## Cron

El módulo registra el job `ticpan_agent_heartbeat` en el grupo `default` de Magento, con ejecución **cada hora** (`0 * * * *`). Ticpan espera al menos un reporte cada 24 horas; si no llega ninguno, la tienda pasa a estado **"Expirado"** en el dashboard.

Verifica que el cron de Magento está activo:

```bash
bin/magento cron:run
# o revisa cron_schedule:
# SELECT job_code, status, finished_at FROM cron_schedule WHERE job_code = 'ticpan_agent_heartbeat' ORDER BY finished_at DESC LIMIT 5;
```

---

## Datos recopilados

El agente recopila **únicamente métricas técnicas** del servidor. Nunca se envían datos de clientes, pedidos, precios ni contenido de catálogo en texto plano.

| Pilar | Qué recopila |
|-------|-------------|
| **Rendimiento** | Modo Magento, cachés, Redis, OPCache, Elasticsearch, indexers, static content |
| **Seguridad** | URL admin, 2FA, política de contraseñas, CAPTCHA, permisos de ficheros |
| **OPS** | Estado del cron, message queues, uso de disco, backup reciente |
| **Código** | `composer.lock`, excepciones PHP (conteo), errores JS (conteo), cumplimiento PSR-12 |

Las reglas que requieren acceso externo (TLS, DNS, PageSpeed, headers HTTP) las evalúa directamente el cloud Ticpan sin necesidad del agente.

---

## Seguridad

Cada payload se firma con **HMAC-SHA256** usando la clave secreta configurada:

```
X-Ticpan-Signature: sha256=<hex>
X-Ticpan-Timestamp: <unix_timestamp>
X-Ticpan-Store-Id: <uuid>
```

El cloud rechaza peticiones con timestamp fuera de una ventana de ±5 minutos y peticiones duplicadas (anti-replay). La clave secreta se almacena cifrada en `core_config_data` mediante el sistema de cifrado nativo de Magento.

---

## Logs

Todas las trazas del módulo van a `var/log/system.log` con el prefijo `W2e_Ticpan:`.

```bash
grep "W2e_Ticpan" var/log/system.log
```

---

## Compatibilidad con Adobe Commerce Cloud

El módulo detecta automáticamente si está instalado en Adobe Commerce Cloud (presencia de `magento/magento-cloud-metapackage` en `composer.lock`) e incluye el flag `is_cloud_edition: true` en el payload. El cloud Ticpan ajusta la evaluación de ciertas reglas que no aplican en entornos Cloud (permisos de ficheros, detección de static content deploy).

---

## Soporte

- Dashboard y onboarding: [ticpan.app](https://ticpan.app)
- Issues: [github.com/way2ecommerce/ticpan-agent/issues](https://github.com/way2ecommerce/ticpan-agent/issues)
