# DL/ID support matrix

This matrix tracks evidence that the parser works with issued cards. Parsing an
AAMVA data-element designator is **structural support**; it is not proof that a
specific jurisdiction and card generation has been tested.

## Status definitions

- **Pending**: no approved fixture is present in the repository.
- **Synthetic**: generated data tests the documented structure only.
- **Verified**: a de-identified scan from that jurisdiction/card generation is
  covered by an automated fixture and reviewed against the applicable standard.
- **Exception**: a known issuer deviation has a documented compatibility rule.

A jurisdiction may be advertised as supported only when its currently issued
driver license and identification card rows are Verified. Historical support
must name the issue-year range and AAMVA version; it must never be implied by a
current-card result.

## United States and District of Columbia

| Jurisdiction | Code | Current DL | Current ID | Historical generations |
|---|---:|---:|---:|---:|
| Alabama | AL | Synthetic | Synthetic | Pending |
| Alaska | AK | Synthetic | Synthetic | Pending |
| Arizona | AZ | Synthetic | Synthetic | Pending |
| Arkansas | AR | Synthetic | Synthetic | Pending |
| California | CA | Synthetic | Synthetic | Pending |
| Colorado | CO | Synthetic | Synthetic | Pending |
| Connecticut | CT | Synthetic | Synthetic | Pending |
| Delaware | DE | Synthetic | Synthetic | Pending |
| District of Columbia | DC | Synthetic | Synthetic | Pending |
| Florida | FL | Synthetic | Synthetic | Pending |
| Georgia | GA | Synthetic | Synthetic | Pending |
| Hawaii | HI | Synthetic | Synthetic | Pending |
| Idaho | ID | Synthetic | Synthetic | Pending |
| Illinois | IL | Synthetic | Synthetic | Pending |
| Indiana | IN | Synthetic | Synthetic | Pending |
| Iowa | IA | Synthetic | Synthetic | Pending |
| Kansas | KS | Synthetic | Synthetic | Pending |
| Kentucky | KY | Synthetic | Synthetic | Pending |
| Louisiana | LA | Synthetic | Synthetic | Pending |
| Maine | ME | Synthetic | Synthetic | Pending |
| Maryland | MD | Synthetic | Synthetic | Pending |
| Massachusetts | MA | Synthetic | Synthetic | Pending |
| Michigan | MI | Synthetic | Synthetic | Pending |
| Minnesota | MN | Synthetic | Synthetic | Pending |
| Mississippi | MS | Synthetic | Synthetic | Pending |
| Missouri | MO | Synthetic | Synthetic | Pending |
| Montana | MT | Synthetic | Synthetic | Pending |
| Nebraska | NE | Synthetic | Synthetic | Pending |
| Nevada | NV | Synthetic | Synthetic | Pending |
| New Hampshire | NH | Synthetic | Synthetic | Pending |
| New Jersey | NJ | Synthetic | Synthetic | Pending |
| New Mexico | NM | Synthetic | Synthetic | Pending |
| New York | NY | Synthetic | Synthetic | Pending |
| North Carolina | NC | Synthetic | Synthetic | Pending |
| North Dakota | ND | Synthetic | Synthetic | Pending |
| Ohio | OH | Synthetic | Synthetic | Pending |
| Oklahoma | OK | Synthetic | Synthetic | Pending |
| Oregon | OR | Synthetic | Synthetic | Pending |
| Pennsylvania | PA | Synthetic | Synthetic | Pending |
| Rhode Island | RI | Synthetic | Synthetic | Pending |
| South Carolina | SC | Synthetic | Synthetic | Pending |
| South Dakota | SD | Synthetic | Synthetic | Pending |
| Tennessee | TN | Synthetic | Synthetic | Pending |
| Texas | TX | Synthetic | Synthetic | Pending |
| Utah | UT | Synthetic | Synthetic | Pending |
| Vermont | VT | Synthetic | Synthetic | Pending |
| Virginia | VA | Synthetic | Synthetic | Pending |
| Washington | WA | Synthetic | Synthetic | Pending |
| West Virginia | WV | Synthetic | Synthetic | Pending |
| Wisconsin | WI | Synthetic | Synthetic | Pending |
| Wyoming | WY | Synthetic | Synthetic | Pending |

U.S. territories and Canadian jurisdictions are out of the initial fifty-state
acceptance gate and will be tracked in separate tables when approved fixtures
are available.

## Fixture acceptance gate

Each Verified entry requires:

1. Jurisdiction, DL/ID type, issue-year range, AAMVA version, jurisdiction
   version, and source provenance recorded without personal information.
2. Raw scanner output retained with exact control characters, plus expected
   metadata, normalized values, all elements, and subfile membership.
3. At least one current DL and one current ID fixture; distinct generations,
   subfiles, or issuer exceptions need separate fixtures.
4. Tests for raw, Base64, hexadecimal-byte, CR/LF, and supported scanner
   transformations where those representations occur in production.
5. Independent review that the fixture is authorized, de-identified, and not
   fabricated from undocumented assumptions.

## AAMVA version gate

`Parser::parseDocument()` currently records ANSI header versions `00` through
`10`, preserves every three-letter element, and warns on later versions. This
is structural compatibility, not version certification. Every version claimed
as supported must have fixtures for its header, directory rules, mandatory and
optional elements, date/name rules, repeated values, and jurisdiction subfiles.

