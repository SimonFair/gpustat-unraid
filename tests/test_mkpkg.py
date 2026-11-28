"""Run on Linux with the GNU tools used by mkpkg; makepkg is stubbed."""

import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "src" / "mkpkg"


class PackageChecksumTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / "release-workspace"
        for directory in ("src/gpustat", "pkg", "bin", "temporary"):
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        shutil.copy2(SCRIPT, self.root / "src/mkpkg")
        (self.root / "src/gpustat/payload").write_text("plugin payload\n")
        self.manifest = self.root / "gpustat.plg"
        self.original = (
            '<!ENTITY version "2026.05.19">\n'
            '<!ENTITY sha256 "' + "0" * 64 + '">\n'
            '<SHA256>&sha256;</SHA256>\n'
        )
        self.manifest.write_text(self.original)
        self.command("date", 'printf "2026.11.28\\n"\n')
        self.command(
            "makepkg",
            'if [[ "${FAIL_BUILD:-0}" == 1 ]]; then exit 1; fi\n'
            'printf "built package\\n" > "${@: -1}"\n',
        )
        self.environment = dict(os.environ)
        self.environment.update(
            PATH=str(self.root / "bin") + os.pathsep + os.environ["PATH"],
            TMPDIR=str(self.root / "temporary"),
        )

    def command(self, name, body):
        path = self.root / "bin" / name
        path.write_text("#!/bin/bash\nset -e\n" + body)
        path.chmod(0o755)

    def build(self):
        result = subprocess.run(
            ["bash", str(self.root / "src/mkpkg"), "gpustat"],
            cwd=self.root,
            env=self.environment,
            capture_output=True,
            text=True,
        )
        self.assertEqual(list((self.root / "temporary").iterdir()), [])
        return result

    def assert_package(self, version):
        package = self.root / "pkg" / f"gpustat-{version}-x86_64.txz"
        digest = hashlib.sha256(package.read_bytes()).hexdigest()
        sidecar = package.with_suffix(".sha256")
        self.assertEqual(sidecar.read_text(), f"{digest}  {package.name}\n")
        self.assertIn(f'<!ENTITY sha256 "{digest}">', self.manifest.read_text())
        self.assertIn(f'<!ENTITY version "{version}">', self.manifest.read_text())
        self.assertEqual(list((self.root / "pkg").glob("*.md5")), [])

    def test_sha256_sidecar_and_manifest_match_package(self):
        result = self.build()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assert_package("2026.11.28")

    def test_existing_package_uses_matching_version_suffix(self):
        previous = self.root / "pkg/gpustat-2026.11.28-x86_64.txz"
        previous.write_bytes(b"previous package")
        result = self.build()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(previous.read_bytes(), b"previous package")
        self.assert_package("2026.11.28a")

    def test_failed_build_preserves_manifest(self):
        self.environment["FAIL_BUILD"] = "1"
        self.assertNotEqual(self.build().returncode, 0)
        self.assertEqual(self.manifest.read_text(), self.original)
        self.assertEqual(list((self.root / "pkg").iterdir()), [])

    def test_failed_verification_preserves_manifest(self):
        checksum = shutil.which("sha256sum")
        self.command(
            "sha256sum",
            'if [[ "$1" == --check ]]; then exit 1; fi\n'
            f'exec "{checksum}" "$@"\n',
        )
        self.assertNotEqual(self.build().returncode, 0)
        self.assertEqual(self.manifest.read_text(), self.original)
        self.assertEqual(list((self.root / "pkg").iterdir()), [])

    def test_missing_sha256_entity_stops_before_build(self):
        content = '<!ENTITY version "2026.05.19">\n'
        self.manifest.write_text(content)
        self.assertNotEqual(self.build().returncode, 0)
        self.assertEqual(self.manifest.read_text(), content)
        self.assertEqual(list((self.root / "pkg").iterdir()), [])


if __name__ == "__main__":
    unittest.main()
