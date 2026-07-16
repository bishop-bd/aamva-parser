# Fixture corpus

Fixtures are the evidence behind `docs/support-matrix.md`. Never commit a scan
containing real personal data, a portrait, a signature, or an actual document
number. Possessing a sample does not imply permission to redistribute it.

## Layout

Use one JSON file per materially different card generation:

```text
tests/Fixtures/
  US/
    TX/
      dl-current-aamva-09.json
      id-current-aamva-09.json
```

Each file must contain:

```json
{
  "jurisdiction": "TX",
  "country": "USA",
  "cardType": "DL",
  "issueYears": "2024-2028",
  "aamvaVersion": 9,
  "jurisdictionVersion": 0,
  "status": "synthetic",
  "provenance": "Generated from documented fields; no real person data",
  "encoding": "base64",
  "payload": "...",
  "expected": {
    "metadata": {
      "complianceIndicator": true,
      "fileType": "ANSI",
      "issuerIdentificationNumber": "636026",
      "aamvaVersion": 9,
      "standardPublicationYear": 2020,
      "jurisdictionVersion": 0,
      "declaredSubfileCount": 1
    },
    "normalized": {},
    "elements": {},
    "subfileTypes": ["DL"],
    "warnings": []
  }
}
```

`status` is `synthetic` or `verified`. Verified data must be irreversibly
de-identified while preserving lengths, separators, directories, optional
elements, and issuer quirks. If de-identification changes those properties,
replace values and recalculate offsets and lengths before approval.

## Review checklist

1. Confirm authorization and remove all personally identifying information.
2. Compare the decoded payload with the applicable AAMVA standard.
3. Record issuer deviations instead of silently making the parser permissive.
4. Add the fixture to the corpus-driven PHPUnit test.
5. Update `docs/support-matrix.md` only after review and a passing CI run.

