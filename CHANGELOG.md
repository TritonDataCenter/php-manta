# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [2.0.0] - ?
### Added
 - Added support for composer.
 - Added version number.
 - Added support for Manta environment variables.
 - Added unit tests.
 - Added explicit exception class - MantaException.
 
### Changed
 - Moved existing project from bitbucket to github.
 - Changed ownership to Joyent.
 - Changed license to MPLv2.
 - Broke embedded classes into separate files. 
 - Reformatted code to comply with PSR2.
 - Changed documentation generation from Doxygen to phpDocumentor. 
 - Manta paths are now specified absolutely and not relative to /account/stor.
 - We now encode file and directory paths in a URL safe encoding.
 - Minimum PHP version is now 5.6.
 - Switched client HTTP library from libcurl to guzzle.
