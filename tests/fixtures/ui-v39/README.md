# v39 wallet refresh provenance

See sources.json. Dhani and13L refresh SVG path geometry is identical; only the stroke differs. Original Dhani SVG438B is embedded unchanged; no redraw.
The native Wallet component already binds onClick:updateBalance. Both hooks use /Lottery/GetBalance and the same loading/success/failure/finally flow. The patch preserves the actual existing span and native click handler, rather than replacing wallet logic.
Tests run the extracted native Wallet and updateBalance function in productionVue with deferred simulated provider replies. No authenticated wallet requests or wagers are made. Six-width fixture results cover API guard/failure/validzero, re-render/host identity, keyboard, original routes and off-route cleanup. Fixture balance values are synthetic, not account data.
