# Changelog

## 5.0.0 - 2026-03-15

### Changed

- Requires PHP `8.4`
- Requires `innmind/foundation:~2.1`
- `Innmind\IPC\IPC` is a final class now
- `Innmind\IPC\Message` is a final class now
- `Innmind\IPC\Process\Name::maybe()` has been replace by `::attempt()`
- `Innmind\IPC\Process` is a final class now
- `Innmind\IPC\Protocol` is an internal final class now
- `Innmind\IPC\Server::__invoke()` has been replaced by `::with()`

### Removed

- `Innmind\IPC\Client\Unix`
- `Innmind\IPC\Exception\*`
- `Innmind\IPC\IPC\Unix`, use `Innmind\IPC\IPC` instead
- `Innmind\IPC\Message\*`, use `Innmind\IPC\Message` instead
- `Innmind\IPC\Factory`, use `Innmind\IPC\IPC::of()` instead

### Fixed

- PHP `8.4` deprecations

## 4.4.0 - 2024-03-10

### Changed

- Requires `innmind/operating-system:~5.0`

## 4.3.0 - 2023-11-01

### Changed

- Requires `innmind/operating-system:~4.0`

## 4.2.0 - 2023-09-23

### Added

- Support for `innmind/immutable:~5.0`

### Removed

- Support for PHP `8.1`

## 4.1.0 - 2023-01-29

### Changed

- Requires `innmind/socket:~6.0`
- Requires `innmind/server-control:~5.0`
