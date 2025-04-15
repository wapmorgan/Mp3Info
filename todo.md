# Todo

## new tag 0.1.1

- refactored `isValidAudio`:
  - now this method only reads data from provided filename
  - logic moved to new public static method `isValid`
  - added try/cat block in case when `file_get_contents` returns false
- new public static method `isValid`
  - reads data from string and makes a file validity decision
- `composer.json`:
  - created `require-dev` with `phpunit`
  - created `autoload-dev` with folder `tests`
  - created `scripts` with `tests` and `phpunit` to simplify run tests with one command `composer tests`
