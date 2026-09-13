# Security Policy

Laravel Document Extraction processes untrusted files and may send document content to configured AI
providers. We take reports about input handling, resource limits, command execution, temporary files,
provider routing, data exposure, and credential safety seriously.

## Supported versions

Security fixes are provided for the latest published beta or stable release. Earlier prereleases may
require upgrading to receive a fix.

## Reporting a vulnerability

Please do not open a public GitHub issue or include sensitive details in a pull request.

Use either:

- GitHub private vulnerability reporting from the repository's **Security** tab
- Email at [joey@jkudish.com](mailto:joey@jkudish.com)

Include:

- The affected package version
- The document type and execution path involved
- Steps to reproduce the issue with a safe synthetic file when possible
- The impact you observed or believe is possible
- Any suggested fix or mitigation

Do not send private documents, production credentials, provider responses, or other people's data.
If a reproducer requires sensitive material, describe the issue first so a safe transfer method can
be arranged.

Please allow time to investigate and prepare a fix before sharing the issue publicly.

## Using the package safely

Applications are responsible for:

- Deciding who may submit documents
- Choosing which providers may receive document contents
- Storing results safely
- Reviewing any business action based on extracted data
- Setting limits for the environment
- Testing with their own documents

Run the `php artisan extraction:doctor` Artisan command in each deployment.
