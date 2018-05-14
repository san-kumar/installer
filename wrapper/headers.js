'use strict';
var splitter = require('./splitter');

module.exports.parse = function (content) {
    var parts = content.split("\r\n");
    var result = {};

    for (var i = 0; i < parts.length; i++) {
        var [key, val] = splitter.split(parts[i], ':');
        key = (key || '').toLowerCase().trim();
        val = (val || '').trim();

        if (key) {
            if (key === 'set-cookie') {
                var vars = key.split('').map((s, i) => key.substr(0, i) + s.toUpperCase() + key.substr(i + 1));

                for (var j = 0; j < vars.length; j++) {
                    if (!result.hasOwnProperty(vars[j])) {
                        result[vars[j]] = val;
                        break;
                    }
                }
            } else {
                result[key] = val;
            }
        }
    }

    return Object.assign({'content-type': 'text/html', 'status': '200 Found'}, result);
};

//console.log(module.exports.parse("Content-Type: image/png\r\nset-cookie: 1\r\nset-cookie: 2"));