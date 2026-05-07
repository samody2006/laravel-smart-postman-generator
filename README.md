# Laravel Smart Postman Generator

A smarter Laravel-to-Postman exporter focused on generating clean, organized, and documentation-ready Postman collections with minimal manual cleanup.

Built as an enhanced fork of the original:

- Original Package: `andreaselia/laravel-api-to-postman`
- Original Author: Andreas Elia

This project expands the original idea into a more production-ready API documentation generator for modern Laravel applications.

---

# Why Use This?

The common problems with generated Postman collections:

- Endpoints export with raw route paths as names
- Collections become difficult to navigate as APIs grow
- JSON bodies are flat and not representative of actual payloads
- Teams spend time manually renaming requests and organizing folders
- Large APIs require exporting everything repeatedly

This package solves those issues by generating:

- cleaner request names
- grouped request folders
- smarter JSON payloads
- scalable exports
- documentation-ready collections

---

# Installation

```bash
composer require samody/laravel-smart-postman-generator
```

---

# Publish Configuration

```bash
php artisan vendor:publish --tag=postman-config
```

---

# Quick Start

Export entire API:

```bash
php artisan export:postman
```

Export only a specific API section:

```bash
php artisan export:postman --path=api/user
```

Export with JSON request bodies:

```bash
php artisan export:postman --body-mode=json
```

Export with bearer authentication:

```bash
php artisan export:postman --bearer="your-token"
```

---

# Features

## Core Export Features

- ✅ Automatic Laravel route discovery
- ✅ Postman Collection v2.1 export
- ✅ Bearer authentication support
- ✅ Basic authentication support
- ✅ Configurable headers
- ✅ Structured route export
- ✅ CRUD folder generation
- ✅ Docblock descriptions support

---

## Smart Export Features

### Route Path Filtering

Export only a section of your API instead of regenerating the entire collection.

```bash
php artisan export:postman --path=api/admin
```

Useful for:
- modular APIs
- large projects
- incremental documentation updates
- team-based API exports

---

### JSON Body Support

Export request bodies as:

- `json`
- `form-data`
- `urlencoded`

Example:

```php
'body_format' => 'json',
```

---

### Body Mode Detection

Control how request bodies are generated.

```php
'body_mode' => 'auto',
```

Supported modes:

| Mode | Description |
|---|---|
| formdata | Always generate form-data |
| json | Always generate JSON |
| auto | Automatically detect best body type |

---

### FormRequest Validation Export

Automatically parse Laravel FormRequest validation rules and export them into Postman request bodies.

Supports:
- validation descriptions
- required field detection
- nullable fields
- arrays
- nested request fields

---

# Smart Naming System (Planned)

Automatically convert technical route paths into readable Postman request names.

Example:

| Route | Method | Generated Name |
|---|---|---|
| api/user/profile | GET | Get User Profile |
| api/user/logout | POST | Request User Logout |
| api/user/orders/{id}/cancel | POST | Cancel User Order |

This removes the need for manually renaming hundreds of requests after export.

---

# Automatic Folder Grouping (Planned)

Automatically organize requests into folders.

## Group By Controller

```text
UserController
 ├── Get User Profile
 ├── Update User Profile
 └── Logout User
```

## Group By Path

```text
User
 ├── Profile
 ├── Orders
 └── Notifications
```

Config example:

```php
'group_by' => 'controller',
```

Supported options:

```php
'controller'
'path'
'none'
```

---

# Structured JSON Generation (Planned)

Generate real nested JSON payloads from FormRequest validation rules.

## Current Flat Output

```json
{
  "items.*.product_id": "",
  "items.*.quantity": ""
}
```

## Planned Structured Output

```json
{
  "items": [
    {
      "product_id": 1,
      "quantity": 1
    }
  ]
}
```

---

# Example Value Generation (Planned)

Automatically generate intelligent example values.

Example:

```json
{
  "email": "john@example.com",
  "name": "John Doe",
  "quantity": 1,
  "is_active": true
}
```

Potential support:
- Faker integration
- enum value detection
- date examples
- UUID generation
- file placeholders

---

# Configuration

Configuration file:

```text
config/api-postman.php
```

---

## Example Configuration

```php
return [

    'base_url' => env('APP_URL'),

    'structured' => true,

    'crud_folders' => true,

    'body_mode' => 'auto',

    'body_format' => 'json',

    'enable_formdata' => true,

    'smart_naming' => true,

    'group_by' => 'controller',

];
```

---

# Usage

## Export Entire API

```bash
php artisan export:postman
```

---

## Export Specific API Section

```bash
php artisan export:postman --path=api/user
```

---

## Export Using JSON Bodies

```bash
php artisan export:postman --body-mode=json
```

---

## Export With Bearer Token

```bash
php artisan export:postman --bearer="your-token"
```

---

## Export With Basic Auth

```bash
php artisan export:postman --basic="username:password"
```

---

# Output Location

Generated collections are stored in:

```text
storage/app/postman
```

---

# Example Generated Improvements

## Before

```text
api/user/orders/{id}/cancel
```

## After

```text
Cancel User Order
```

---

## Before

```json
{
  "items.*.product_id": ""
}
```

## After

```json
{
  "items": [
    {
      "product_id": 1
    }
  ]
}
```

---

# Roadmap

## Phase 1
- [x] JSON body support
- [x] Body mode support
- [x] Route path filtering

---

## Phase 2
- [ ] Smart request naming
- [ ] Automatic folder grouping
- [ ] Nested path grouping

---

## Phase 3
- [ ] Structured JSON generation
- [ ] Example value generation
- [ ] Faker integration

---

## Phase 4
- [ ] Response example generation
- [ ] OpenAPI support
- [ ] Environment export support
- [ ] Swagger compatibility

---

# Testing

```bash
composer test
```

---

# Real-World Benefits

## Development

✅ Faster API testing  
✅ Cleaner Postman collections  
✅ Less manual cleanup  
✅ Better onboarding for teams  
✅ Easier frontend/backend collaboration  

---

## Documentation

✅ Documentation-ready exports  
✅ Human-readable request names  
✅ Organized request grouping  
✅ Better payload representation  

---

## Team Collaboration

✅ Consistent Postman structures  
✅ Easier API navigation  
✅ Faster endpoint discovery  
✅ Cleaner API handoffs  

---

# Contributing

Contributions are welcome.

You can:
- open issues
- suggest improvements
- submit pull requests

---

# Credits

## Original Package

Andreas Elia  
`andreaselia/laravel-api-to-postman`

---

## Smart Fork & Enhancements

Maintained as an enhanced developer-experience focused fork.

---

# License

MIT
