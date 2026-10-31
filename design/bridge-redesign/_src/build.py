"""Generate every prototype page: python3 _src/build.py (from design/bridge-redesign/)."""
import importlib, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

MODULES = ['p_shell', 'p_portfolio', 'p_board', 'p_usage', 'p_auth', 'p_account', 'p_polymarket', 'p_empty', 'p_index']

for m in MODULES:
    try:
        mod = importlib.import_module(m)
    except ModuleNotFoundError as e:
        if e.name == m:
            continue
        raise
    mod.build()
    print('built', m)
