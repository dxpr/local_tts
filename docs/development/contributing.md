# Contributing

## Issue queue

Report bugs and feature requests on the
[drupal.org issue queue](https://www.drupal.org/project/issues/local_tts).

## Source code

- [git.drupalcode.org/project/local_tts](https://git.drupalcode.org/project/local_tts)
  (primary)
- [github.com/dxpr/local_tts](https://github.com/dxpr/local_tts) (mirror)

Pull requests are accepted on GitHub and mirrored to drupal.org.

## Branch conventions

- `1.x`: main development branch
- Feature branches: `feat/<description>` or `feature/<description>`
- Bug fixes: `fix/<description>`

## Coding standards

The project enforces Drupal coding standards and ESLint for JavaScript.
All checks run automatically on pull requests.

### PHP (Drupal coding standards)

```bash
docker compose --profile lint run --rm drupal-lint
```

Auto-fix coding standard violations:

```bash
docker compose --profile lint run --rm drupal-lint-auto-fix
```

### Drupal compatibility

```bash
docker compose --profile lint run --rm drupal-check
```

### JavaScript (ESLint)

```bash
npm ci
npx eslint .
```
