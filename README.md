# aamva-parser

Parse the machine-readable data from AAMVA-compliant driver's licenses and ID
card PDF417 barcodes into a consistent associative array.

## Requirements

- PHP 8.4 or later

## Installation

```bash
composer require bishopbd/aamva-parser
```

## Usage

```php
use AamvaParser\Parser;

$result = (new Parser())->parse($scannerData);

echo $result['first'];
echo $result['last'];
echo $result['address'];

$json = json_encode($result, JSON_THROW_ON_ERROR);
```

### Scanner input formats

`Parser::parse()` automatically accepts:

- Raw AAMVA text, including binary control separators.
- Space- or comma-separated hexadecimal bytes, such as
  `40 0A 1E 0D 41 4E 53 49 ...`.
- Standard padded or unpadded Base64 containing AAMVA data.
- Layered transport data, such as hex bytes that decode to a Base64 AAMVA
  payload.

Transport bytes before the `@` compliance indicator and control bytes after
the last record are ignored. Conversion is also available directly:

```php
use AamvaParser\DataHandler;

$handler = new DataHandler();
$rawFromHex = $handler->fromByteString($hexByteString);
$rawFromBase64 = $handler->base64Decode($base64String);
```

Explicit conversion methods throw `InvalidArgumentException` for malformed
input. Parser auto-detection only decodes a value when its decoded content
looks like AAMVA data, preventing ordinary scan text from being misinterpreted.

`parse()` always returns every normalized key. Missing optional data is an empty
string:

```php
[
    'first' => 'JANE',
    'last' => 'DOE',
    'mid' => 'QUINN',
    'address' => '123 FAKE ST',
    'address2' => '',
    'city' => 'EXAMPLETOWN',
    'state' => 'TX',
    'zip' => '750010123',
    'expMM' => '12',
    'expDD' => '31',
    'expYYYY' => '2030',
    'dobMM' => '07',
    'dobDD' => '15',
    'dobYYYY' => '1992',
    'fullEXP' => '20303112',
]
```

Names and addresses are mapped from `DAC`, `DCS`, `DAD`, `DAG`, `DAH`, `DAI`,
`DAJ`, and `DAK`. Legacy `DAA`, `DAB`, and `DCT` name fields are supported as
fallbacks. Expiration (`DBA`) and birth (`DBB`) dates support both `MMDDYYYY`
and `YYYYMMDD`. To match the output contract above, `fullEXP` is `YYYYDDMM`.

The parser accepts compliant control-character separators as well as common
scanner transformations such as CR/LF records, pipe-delimited records, a UTF-8
BOM, leading scanner text, omitted headers, and human-readable line formatting.
It throws `InvalidArgumentException` when no AAMVA data elements can be found.

## Standard

The header, subfile directory, and data element handling follow the
[AAMVA DL/ID Card Design Standard 2025](https://www.aamva.org/getmedia/81af105d-8b1b-45e1-aa46-f1800a259ed1/AAMVADLIDCardDesignStandard2025.pdf),
published by the
[AAMVA Card Design Standard Subcommittee](https://www.aamva.org/drivers/subcommittees-working-groups/card-design-standard-subcommittee-be78411d579392b1eb755bb060492f3b).

## Testing

```bash
composer test
```
