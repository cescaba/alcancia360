# Billetera App - Template WordPress

Template de WordPress funcional con sistema de autenticación obligatoria, gestión de ventas y saldo para asesores.

## Características

- ✅ Autenticación obligatoria
- ✅ Rol personalizado "Asesor de Ventas"
- ✅ Dashboard con estadísticas de ventas
- ✅ Formulario para registrar ventas
- ✅ Tabla de ventas recientes
- ✅ Sistema de saldo total
- ✅ Interfaz responsive y moderna
- ✅ Eliminación de ventas

## Instalación

1. El tema ya está ubicado en `/wp-content/themes/billetera-app/`
2. Accede a WordPress Admin → Apariencia → Temas
3. Activa el tema "Billetera App"

## Configuración Inicial

### 1. Crear página de Login
1. Accede a WordPress Admin → Páginas → Añadir nueva
2. Título: "Iniciar Sesión"
3. Contenido: Deja vacío (WordPress usa la página de login por defecto)
4. Publica la página

### 2. Crear página de Dashboard
1. Accede a WordPress Admin → Páginas → Añadir nueva
2. Título: "Dashboard"
3. Contenido: Agrega el shortcode `[billetera_dashboard]`
4. Publica la página

### 3. Crear página de Formulario de Ventas
1. Accede a WordPress Admin → Páginas → Añadir nueva
2. Título: "Registrar Venta"
3. Contenido: Agrega el shortcode `[billetera_sales_form]`
4. Publica la página

## Crear Usuarios con Rol de Asesor

1. Accede a WordPress Admin → Usuarios → Añadir nuevo
2. Completa los datos:
   - Usuario
   - Email
   - Contraseña
   - Nombre
   - Apellido
3. En "Rol", selecciona "Asesor de Ventas"
4. Haz clic en "Agregar nuevo usuario"

## Shortcodes Disponibles

### `[billetera_dashboard]`
Muestra el dashboard con:
- Saldo total del usuario
- Número de ventas realizadas
- Promedio de venta
- Tabla de ventas recientes

**Restricción:** Solo visible para usuarios con rol "Asesor"

### `[billetera_sales_form]`
Muestra el formulario para registrar nuevas ventas con campos:
- Monto (requerido)
- Descripción (opcional)

**Restricción:** Solo visible para usuarios con rol "Asesor"

## Estructura de Base de Datos

### Tabla: `wp_billetera_sales`
```
- id (int) - ID único de la venta
- user_id (bigint) - ID del usuario que realizó la venta
- amount (decimal) - Monto de la venta
- description (text) - Descripción de la venta
- created_at (datetime) - Fecha y hora de creación
- updated_at (datetime) - Fecha y hora de última actualización
```

## API AJAX

### Agregar venta: `billetera_add_sale`
**Parámetros:**
- `amount` (float, requerido) - Monto de la venta
- `description` (string, opcional) - Descripción
- `nonce` (string, requerido) - Token de seguridad

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": {
    "message": "Venta registrada correctamente."
  }
}
```

### Eliminar venta: `billetera_delete_sale`
**Parámetros:**
- `sale_id` (int, requerido) - ID de la venta
- `nonce` (string, requerido) - Token de seguridad

## Estilos Disponibles

El tema incluye CSS moderno con variables personalizables:

```css
:root {
  --primary-color: #2563eb;
  --primary-dark: #1e40af;
  --success-color: #10b981;
  --danger-color: #ef4444;
  --warning-color: #f59e0b;
  /* ... más variables */
}
```

Puedes personalizar los colores editando `style.css`.

## Flujo de Uso

1. Usuario anónimo intenta acceder a cualquier página
2. Es redirigido automáticamente a la página de login
3. Se autentica con sus credenciales
4. Es redirigido al dashboard
5. Puede ver sus ventas y su saldo total
6. Puede registrar nuevas ventas
7. Puede eliminar ventas registradas

## Seguridad

- Todos los formularios incluyen validación CSRF (nonces)
- Las acciones AJAX están protegidas con permisos de usuario
- Los datos se sanitizan antes de ser insertados en la BD
- Las ventas se validan antes de ser guardadas

## Soporte

Para reportar problemas o sugerencias, contacta con el equipo de desarrollo.

## Licencia

GPL v2 o superior
