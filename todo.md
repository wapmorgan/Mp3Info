# Todo

Make it possible for the service to receive information not only from the file but also from the string

- create a simple test too see that all works correctly
- refactor construct method
  - create a public static method `fromFile`
  - method `__construct` must be private
  - create a public static method `fromString`
- update readme

## new

- refactored `isValidAudio`:
  - now this method only reads data from provided filename
  - logic moved to new protected static method `isValid`
- new protected static method `isValid`
  - reads data from string and makes a file validity decision
- `composer.json`:
  - created `require-dev` with `phpunit`
  - created `autoload-dev` witg folder `tests`
  - created `scripts` with `tests` and `phpunit` to simplify run tests with one command `composer tests`
