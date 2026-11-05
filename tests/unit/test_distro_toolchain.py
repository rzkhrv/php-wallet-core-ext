"""Guard OS-specific build prerequisites and isolated compiler caches."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
DOCKERFILE = ROOT / 'docker/Dockerfile'
INSTALLER = ROOT / 'tools/docker/install-dependencies.sh'


class DistroToolchainTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dockerfile = DOCKERFILE.read_text()
        text = INSTALLER.read_text()
        cls.fedora = text.split('  fedora)\n', 1)[1].split('  rockylinux|almalinux)\n', 1)[0]
        cls.enterprise = text.split('  rockylinux|almalinux)\n', 1)[1].split('esac\n\n# Ccache', 1)[0]
        cls.installer = text

    def test_package_installation_is_in_a_single_bootstrap_script(self):
        self.assertIn('COPY tools/docker/install-dependencies.sh ', self.dockerfile)
        self.assertIn('RUN sh /usr/local/bin/install-dependencies.sh', self.dockerfile)
        for installer in ('apt-get install', 'apk add', 'dnf install'):
            self.assertNotIn(installer, self.dockerfile)
            self.assertIn(installer, self.installer)
        self.assertIn('. /etc/os-release', self.installer)
        self.assertIn('ccache', self.installer)
        self.assertIn('/opt/walletcore-ccache/bin/', self.installer)

    def test_fedora_installs_which_for_wallet_core_generate_files(self):
        self.assertIn('dnf install -y', self.fedora)
        self.assertIn(' wget which unzip ', self.fedora)

    def test_el9_preserves_preinstalled_curl_minimal(self):
        self.assertIn('ca-certificates curl-minimal wget which unzip ', self.enterprise)
        self.assertNotIn('ca-certificates curl wget ', self.enterprise)
        self.assertNotIn('--allowerasing', self.enterprise)
        self.assertIn('dnf config-manager --set-enabled crb', self.enterprise)
        self.assertIn('epel-release', self.enterprise)


if __name__ == '__main__':
    unittest.main()
