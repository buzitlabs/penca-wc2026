# Penca WC2026 — Contexto del Proyecto

## Cliente
Organización benéfica (sin fines de lucro), Montevideo, Uruguay.

## Descripción
Plugin WordPress para una penca (pool de predicciones) del Mundial 2026.  
Producto: plugin PHP propio, no hay tema visual complejo.

## Estado actual
- En desarrollo — etapa inicial
- Arquitectura discutida, pendiente de implementación

## Stack
- WordPress (plugin standalone)
- PHP
- Sin WooCommerce (lógica propia de pagos/acceso por códigos físicos)

## Funcionalidades requeridas
- Lógica de scoring personalizada para predicciones
- Códigos de acceso físicos (no registro online estándar)
- Zona horaria Uruguay (UTC-3) forzada en toda la lógica de fechas
- Sin integración de pago digital (acceso por códigos impresos)

## Archivos del proyecto
- `plugin/` → código fuente PHP del plugin
- `docs/` → especificación funcional, decisiones de arquitectura

## Decisiones técnicas tomadas
- Plugin standalone (no depende de WooCommerce)
- Zona horaria hardcodeada a America/Montevideo
- Códigos físicos generados y distribuidos manualmente

## Pendientes críticos
- [ ] Definir estructura de base de datos (tablas custom)
- [ ] Definir lógica de scoring completa
- [ ] Implementar generación y validación de códigos de acceso
- [ ] Construir panel de administración en WordPress

## Notas
- Cliente sin fines de lucro → presupuesto ajustado
- El Mundial 2026 tiene fechas fijas: considerar deadlines reales del torneo
