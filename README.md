# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Versión:** 2.2.0  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## 🆕 Novedades v2.2.0

- ✅ **Helpers API centralizados** - Código más limpio y mantenible
- ✅ **Fix Foreign Key** - `promo_combo_items` ahora con CASCADE correcto
- ✅ **Limpieza de código** - Eliminados archivos redundantes
- ✅ **Script de upgrade SQL** - Mejoras de base de datos

Ver [CHANGELOG.md](CHANGELOG.md) para detalles completos.

---

## ✨ Características Principales

### 🏪 Punto de Venta (Caja)
- Carga rápida por código de barras o búsqueda
- Soporte para productos **pesables** (carnicería, fiambres, frutas)
- **Split payments** (pagos con 2 medios)
- Descuentos globales por monto o porcentaje
- Generación de ticket térmico (58mm/80mm)
- Atajos de teclado (F2 cobrar, F4 cancelar, F5 foco)

### 📦 Productos & Stock
- ABM con panel lateral de edición
- Stock mínimo con alertas
- Productos pesables (3 decimales) vs unitarios
- Categorías, marcas y proveedores

### 🎁 Promociones
- **NxM**: Llevás N, pagás M (ej: 3x2)
- **N° al X%**: Cada N unidades, descuento del X%
- **Combos fijos**: Productos combinados a precio especial
- Motor centralizado (PromoEngine)

### 🖥️ Multi-Terminal
- Soporte para múltiples cajas/terminales
- Sistema de locks para evitar conflictos
- Heartbeat para detectar cajas inactivas

### 👥 Usuarios & Seguridad
- RBAC (Roles y Permisos)
- CSRF protection en todos los forms
- Auditoría de acciones
- Sesiones seguras

### 📊 Reportes
- Dashboard con estadísticas
- Historial de ventas con filtros
- Exportación CSV
- Historial de caja

---

## 🚀 Instalación Rápida

### 1. Requisitos
- PHP 8.0+ (recomendado 8.1/8.2)
- MySQL/MariaDB 5.7+
- Apache con mod_rewrite (XAMPP recomendado en Windows)
- Extensiones PHP: `pdo_mysql`, `mbstring`, `openssl`

### 2. Configuración
```bash
# Clonar o descomprimir
cd /htdocs/flus

# Copiar configuración
cp src/config.example.php src/config.php

# Editar credenciales de BD
nano src/config.php
```

### 3. Base de Datos
```sql
CREATE DATABASE kiosco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Luego importar el schema (disponible en instalador web).

### 4. Acceder
```
http://localhost/flus/public/install.php
```

---

## 📁 Estructura del Proyecto

```
flus/
├── public/              # Archivos web accesibles
│   ├── api/            # Endpoints REST
│   ├── assets/         # CSS, JS
│   ├── includes/       # PromoEngine, Controllers
│   ├── lib/            # Helpers core
│   └── partials/       # Fragmentos HTML
├── src/                # Lógica backend
│   ├── config.php      # Configuración (crear desde example)
│   ├── helpers.php     # Funciones globales
│   └── *.php           # Controllers y libs
├── storage/            # Archivos generados (backups, logs)
└── scripts/            # Scripts CLI
```

---

## 🔐 Permisos Disponibles

| Permiso | Descripción |
|---------|-------------|
| `realizar_ventas` | Usar la caja |
| `cerrar_caja` | Cerrar sesión de caja |
| `editar_productos` | ABM de productos |
| `editar_stock` | Ajustar stock |
| `ver_reportes` | Ver historial de ventas |
| `administrar_usuarios` | Gestionar usuarios y roles |
| `gestionar_backups` | Crear/restaurar backups |
| `administrar_config` | Configuración del sistema |
| `caja_modificar_precio` | Cambiar precios en caja |

---

## 📡 API Endpoints

| Endpoint | Método | Descripción |
|----------|--------|-------------|
| `?action=buscar_producto` | GET | Buscar por código |
| `?action=buscar_productos` | GET | Buscar por nombre (autocomplete) |
| `?action=calcular_carrito` | POST | Calcular precios con promos |
| `?action=registrar_venta` | POST | Registrar venta |
| `?action=listar_promos_activas` | GET | Promociones vigentes |
| `?action=terminal_list` | GET | Listar terminales |

---

## 🛠️ Desarrollo

### Convenciones
- PHP: `declare(strict_types=1)` en todos los archivos
- SQL: Prepared statements obligatorios
- JS: ES6+ sin transpilación
- CSS: BEM-like con prefijos por módulo

### Testing
```bash
# Verificar sintaxis PHP
find . -name "*.php" -exec php -l {} \;

# Verificar que la API responde
curl http://localhost/flus/public/api/index.php?action=health
```

---

## 🔄 Upgrade desde versiones anteriores

### Desde v2.1.x a v2.2.0

1. **Backup obligatorio**
```bash
mysqldump -u root -p kiosco > backup_antes_v220.sql
```

2. **Reemplazar archivos**
- Subir los nuevos archivos (conservar `src/config.php`)

3. **Ejecutar script SQL**
```bash
mysql -u root -p kiosco < scripts/upgrade_v220.sql
```

4. **Verificar**
- Entrar al sistema y probar crear/eliminar un producto que esté en un combo
- Si funciona sin errores, el upgrade fue exitoso
