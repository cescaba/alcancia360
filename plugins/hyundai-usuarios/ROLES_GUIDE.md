# Guía de Roles - Hyundai Usuarios

## Resumen de Roles Creados

El plugin crea 4 roles automáticamente con niveles de acceso escalonados:

---

## 1. 👤 Asesor
**Slug:** `asesor`  
**Usuarios típicos:** 300+

### Qué pueden hacer
- ✓ Ver su perfil
- ✓ Cambiar su contraseña
- ✓ Ver la sucursal a la que está asignado
- ✓ Ver información de públicos/contenido

### Qué NO pueden hacer
- ✗ Editar dealers o tiendas
- ✗ Crear usuarios
- ✗ Acceder a admin

---

## 2. 👨‍💼 Jefe de Venta
**Slug:** `jefe_de_venta`  
**Usuarios típicos:** 80+

### Qué pueden hacer
- ✓ Todo lo que hace un Asesor
- ✓ Ver datos de su sucursal asignada
- ✓ Ver información de asesores bajo su supervisión
- ✓ (Futuro: Aprobar/ver reportes de asesores)

### Qué NO pueden hacer
- ✗ Editar dealers o tiendas
- ✗ Crear usuarios
- ✗ Eliminar usuarios
- ✗ Acceder a settings

---

## 3. 👔 Gerente
**Slug:** `gerente`  
**Usuarios típicos:** 20

### Qué pueden hacer
- ✓ Todo lo que hacen Asesor y Jefe
- ✓ **Crear** dealers (concesionarias)
- ✓ **Editar** dealers
- ✓ **Crear** tiendas (sucursales)
- ✓ **Editar** tiendas
- ✓ **Asignar** sucursales a usuarios
- ✓ **Asignar** jefes de venta a sucursales

### Qué NO pueden hacer
- ✗ Eliminar dealers/tiendas
- ✗ Crear o eliminar usuarios
- ✗ Cambiar roles de usuarios
- ✗ Acceder a settings globales

---

## 4. 🔑 Administrador Hyundai
**Slug:** `administrador_hyundai`  
**Usuarios típicos:** 5-10

### Qué pueden hacer
- ✓ Todo lo que hacen los otros roles
- ✓ **Gestionar usuarios** (crear, editar, asignar roles)
- ✓ **Gestionar dealers** (crear, editar, eliminar)
- ✓ **Gestionar tiendas** (crear, editar, eliminar)
- ✓ **Gestionar KPIs** (futuro)
- ✓ **Gestionar concursos** (futuro)
- ✓ **Ver reportes**

### Qué NO pueden hacer
- ✗ Cambiar settings globales de WordPress
- ✗ Instalar/desinstalar plugins
- ✗ Cambiar tema
- ✗ Editar `wp-config.php`

---

## 🔄 Comparación de Capabilities

| Capability | Asesor | Jefe | Gerente | Admin HY |
|------------|:------:|:----:|:-------:|:--------:|
| read | ✓ | ✓ | ✓ | ✓ |
| edit_posts | ✗ | ✗ | ✓ | ✓ |
| edit_published_posts | ✗ | ✗ | ✓ | ✓ |
| publish_posts | ✗ | ✗ | ✓ | ✓ |
| delete_posts | ✗ | ✗ | ✓ | ✓ |
| edit_users | ✗ | ✗ | ✗ | ✓ |
| create_users | ✗ | ✗ | ✗ | ✓ |
| delete_users | ✗ | ✗ | ✗ | ✓ |
| list_users | ✗ | ✗ | ✗ | ✓ |
| manage_options | ✗ | ✗ | ✗ | ✗ |

---

## 📋 Casos de Uso

### Scenario 1: Usuario nuevo es asesor
```
1. Admin HY crea usuario con rol "Asesor"
2. Sistema muestra campo "Sucursal Asociada" en perfil
3. Admin HY asigna sucursal
4. Asesor puede editar su perfil y ver su sucursal
```

### Scenario 2: Promoción a Jefe de Venta
```
1. Admin HY edita usuario Asesor
2. Cambia rol de "Asesor" a "Jefe de Venta"
3. Sistema le muestra datos de supervisión
4. Jefe puede monitorear a sus asesores
```

### Scenario 3: Crear nueva sucursal
```
1. Gerente entra a admin
2. Va a Red de Concesionarias → Sucursales
3. Crea nueva sucursal y la asigna a un dealer
4. Asigna un Jefe de Venta a esa sucursal
5. Crea/asigna Asesores a esa sucursal
```

---

## 🔐 Notas de Seguridad

- **Administrador Hyundai** no tiene `manage_options` para evitar que cambien settings globales de WordPress
- Los roles son jerárquicos: Asesor < Jefe < Gerente < Admin HY
- Los cambios de roles se deben hacer desde WordPress Admin por seguridad
- Los passwords se deben cambiar desde el perfil del usuario

---

## 🔧 Agregar más capabilities en el futuro

Si necesitas agregar capabilities a un rol existente:

```php
$role = get_role('gerente');
if ($role) {
    $role->add_cap('delete_posts');
}
```

O usa **Capability Manager Enhanced** desde WordPress Admin.
