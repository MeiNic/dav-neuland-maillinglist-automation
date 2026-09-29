{ pkgs ? import <nixpkgs> { } }:

# Local dev environment matching the production server's PHP 8.3
# (see PLAN.md §2, §9). php83 here already ships ext-imap, ext-curl,
# ext-openssl, ext-mbstring — no extra extensions needed.
pkgs.mkShell {
  name = "dav-mailinglist-moderation";

  buildInputs = [
    pkgs.php83
    pkgs.php83Packages.composer
  ];

  shellHook = ''
    echo "$(php --version | head -n1)"
    echo "$(composer --version)"
  '';
}
