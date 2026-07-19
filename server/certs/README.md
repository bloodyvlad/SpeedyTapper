# Pinned Apple trust roots

`AppleRootCA-G2.pem` and `AppleRootCA-G3.pem` are PEM conversions of the DER
certificates published by the [Apple PKI](https://www.apple.com/certificateauthority/).

Expected SHA-256 DER fingerprints:

- Apple Root CA G2: `C2B9B042DD57830E7D117DAC55AC8AE19407D38E41D88F3215BC3A890444A050`
- Apple Root CA G3: `63343ABFB89A6A03EBB57E9B3F5FA7BE7C4F5C756F3017B3A8C488C3653E9179`

These are public trust anchors, not App Store Connect credentials. Never place
the private App Store Connect `.p8` key in this directory or in Git.
