(function (global) {
    'use strict';

    var FA = '\u06f0\u06f1\u06f2\u06f3\u06f4\u06f5\u06f6\u06f7\u06f8\u06f9';

    global.hastamaToFA = function (value) {
        return String(value).replace(/[0-9]/g, function (digit) {
            return FA[+digit];
        });
    };
}(window));
